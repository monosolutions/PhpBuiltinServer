<?php
/**
 * @author tiger-seo
 */

namespace Codeception\Extension;

use Codeception\Configuration;
use Codeception\Event\SuiteEvent;
use Codeception\Exception\ModuleConfigException;
use Codeception\Platform\Extension;
use Codeception\Exception\ExtensionException;

class PhpBuiltinServer extends Extension
{
    static $events = [
        'suite.before' => ['beforeSuite', 1024],
    ];

    private $requiredFields = ['hostname', 'port', 'documentRoot'];
    private $resource;
    private $pipes;
    private $orgConfig;
    private $port;

    public function __construct($config, $options)
    {
        if (version_compare(PHP_VERSION, '5.4', '<')) {
            throw new ExtensionException($this, 'Requires PHP built-in web server, available since PHP 5.4.0.');
        }

        parent::__construct($config, $options);
        $this->orgConfig = $config;
        $this->validateConfig();

        if (
            !array_key_exists('startDelay', $this->config)
            || !(is_int($this->config['startDelay']) || ctype_digit($this->config['startDelay']))
        ) {
            $this->config['startDelay'] = 1;
        }
    }

    public function __destruct()
    {
        $this->stopServer();
    }

    /**
     * this will prevent cloning
     */
    private function __clone()
    {
    }

    /**
     * @return string
     */
    private function getCommand()
    {
        $parameters = '';
        if (isset($this->config['router'])) {
            $parameters .= ' -dcodecept.user_router="' . $this->config['router'] . '"';
        }
        if (isset($this->config['directoryIndex'])) {
            $parameters .= ' -dcodecept.directory_index="' . $this->config['directoryIndex'] . '"';
        }
        if (isset($this->config['phpIni'])) {
            $parameters .= ' --php-ini "' . $this->config['phpIni'] . '"';
        }
        if ($this->isRemoteDebug()) {
            $parameters .= ' -dxdebug.remote_enable=1';
        }

        $parameters .= ' -dcodecept.access_log="' . Configuration::logDir() . 'phpbuiltinserver.access_log.txt' . '"';

        if (PHP_OS !== 'WINNT' && PHP_OS !== 'WIN32') {
            // Platform uses POSIX process handling. Use exec to avoid
            // controlling the shell process instead of the PHP
            // interpreter.
            $exec = 'exec ';
        } else {
            $exec = '';
        }

        $command = sprintf(
            $exec . PHP_BINARY . ' %s -S %s:%s -t "%s" "%s"',
            $parameters,
            $this->config['hostname'],
            $this->port,
            realpath($this->config['documentRoot']),
            __DIR__ . '/Router.php'
        );

        return $command;
    }

    private function startServer()
    {
        if ($this->resource !== null) {
            return;
        }
        $tries = 0;
        while ($tries < 5) {
            $command = $this->getCommand();
            $descriptorSpec = [
                0 => ['pipe', 'r'],
                1 => ['file', Configuration::logDir() . 'phpbuiltinserver.output.txt', 'w'],
                2 => ['pipe', 'w'],
            ];
            $this->resource = proc_open($command, $descriptorSpec, $this->pipes, null, null, ['bypass_shell' => true]);

            if (!is_resource($this->resource)) {
                throw new ExtensionException($this, 'Failed to start server.');
            }
            // Since data is returned more or less instantly, we need to have nonblocking in case of no-errors.
            sleep(1);
            stream_set_blocking($this->pipes[2], false);
            $stream = stream_get_contents($this->pipes[2]);
            if ($stream) {
                if (preg_match('/reason: Address already in use/', $stream)) {
                    $this->stopServer();
                    $this->writeln('Address already in use, retrying on: ' . ++$this->port);
                    $tries++;
                    if ($tries == 5) {
                        throw new ExtensionException($this, "Failed to start server.");
                    }
                } else {
                    $this->writeln("Got message: {$stream} while starting PHP server");
                }
            } else {
                $_ENV['SERVER_PORT'] = $this->port;
                break;
            }
        }
        $procStatus = proc_get_status($this->resource);
        if (!$procStatus['running']) {
            proc_close($this->resource);
            throw new ExtensionException($this, 'Failed to start server.');
        }
        $max_checks = 10;
        $checks = 0;
        $this->write("Waiting for the PHP server to be reachable");
        while (true) {
            if ($checks >= $max_checks) {
                throw new ExtensionException($this, 'PHP server never became reachable');
                break;
            }

            if ($fp = @fsockopen($this->config['hostname'], $this->port, $errCode, $errStr, 10)) {
                $this->writeln('');
                $this->writeln("PHP server is now reachable");
                fclose($fp);
                break;
            }

            $this->write('.');
            $checks++;

            // Wait before checking again
            sleep(1);
        }

        // Clear progress line writing
        $this->writeln('');
    }

    private function stopServer()
    {

        if ($this->resource !== null) {
            $this->write("Stopping PHP Server");

            // Wait till the server has been stopped
            $max_checks = 10;
            for ($i = 0; $i < $max_checks; $i++) {
                // If we're on the last loop, and it's still not shut down, just
                // unset resource to allow the tests to finish
                if ($i == $max_checks - 1 && proc_get_status($this->resource)['running'] == true) {
                    $this->writeln('');
                    $this->writeln("Unable to properly shutdown PHP server");
                    unset($this->resource);
                    $this->resource = null;
                    break;
                }

                // Check if the process has stopped yet
                if (proc_get_status($this->resource)['running'] == false) {
                    $this->writeln('');
                    $this->writeln("PHP server stopped");
                    unset($this->resource);
                    $this->resource = null;
                    break;
                }

                foreach ($this->pipes as $pipe) {
                    fclose($pipe);
                }
                proc_terminate($this->resource, 2);

                $this->write('.');

                // Wait before checking again
                sleep(1);
            }
        }
    }

    private function isRemoteDebug()
    {
        // compatibility with Codeception before 1.7.1
        if (method_exists('\Codeception\Configuration', 'isExtensionEnabled')) {
            return Configuration::isExtensionEnabled('Codeception\Extension\RemoteDebug');
        } else {
            return false;
        }
    }

    private function validateConfig()
    {
        $fields = array_keys($this->config);
        if (array_intersect($this->requiredFields, $fields) != $this->requiredFields) {
            throw new ModuleConfigException(
                get_class($this),
                "\nConfig: " . implode(', ', $this->requiredFields) . " are required\n
                Please, update the configuration and set all the required fields\n\n"
            );
        }

        if (false === realpath($this->config['documentRoot'])) {
            throw new ModuleConfigException(
                get_class($this),
                "\nDocument ({$this->config['documentRoot']}) root does not exist. Please, update the configuration.\n\n"
            );
        }

        if (false === is_dir($this->config['documentRoot'])) {
            throw new ModuleConfigException(
                get_class($this),
                "\nDocument root must be a directory. Please, update the configuration.\n\n"
            );
        }

        $this->port = $this->config['port'];
    }

    public function beforeSuite(SuiteEvent $event)
    {

        if (!is_null($this->resource)) {
            $this->stopServer();
        }

        $settings = $event->getSettings();
        $config = [];
        if (array_key_exists("extensions", $settings)) {
            if (array_key_exists("config", $settings["extensions"])) {
                if (array_key_exists("Codeception\\Extension\\PhpBuiltinServer", $settings["extensions"]["config"])) {
                    $config = $settings["extensions"]["config"]["Codeception\\Extension\\PhpBuiltinServer"];
                }
            }
        }
        $this->config = array_merge($this->orgConfig, $config);
        $this->validateConfig();
        $this->startServer();

        if (isset($settings["extensions"]["enabled"]) && in_array(
                trim(get_class($this), "/"),
                $settings["extensions"]["enabled"]
            )
        ) {
            $this->startServer();
        }
        // dummy to keep reference to this instance, so that it wouldn't be destroyed immediately
    }

    public function afterSuite(SuiteEvent $event)
    {
        $this->stopServer();
    }
}
