<?php

    namespace App\Library;

    use Monolog\Formatter\LineFormatter;
    use Monolog\Handler\StreamHandler;
    use Monolog\Logger;

    class Log
    {
        public static $logger;
        public static $path;


        public static function debug($message)
        {
            self::$logger->debug($message);
        }


        public static function info($message)
        {
            self::$logger->info($message);
        }

        public static function notice($message)
        {
            self::$logger->notice($message);
        }


        public static function warning($message)
        {
            self::$logger->warning($message);
        }


        public static function error($message)
        {
            self::$logger->error($message);
        }

        public static function critical($message)
        {
            self::$logger->critical($message);
        }

        public static function alert($message)
        {
            self::$logger->alert($message);
        }

        public static function emergency($message)
        {
            self::$logger->emergency($message);
        }

        public static function fork()
        {
            $pid       = getmypid();
            $output    = '[%datetime%] #' . $pid . " %level_name%: %message%\n";
            $formatter = new LineFormatter($output);

            $stream = new StreamHandler(self::$path, Logger::INFO);
            $stream->setFormatter($formatter);

            self::$logger = new Logger('mailer');
            self::$logger->pushHandler($stream);
        }


        public static function configure($path)
        {
            $pid       = getmypid();
            $output    = '[%datetime%] #' . $pid . " %level_name%: %message%\n";
            $formatter = new LineFormatter($output);

            $stream = new StreamHandler($path, Logger::INFO);
            $stream->setFormatter($formatter);

            self::$logger = new Logger('mailer');
            self::$logger->pushHandler($stream);
            self::$path = $path;
        }

        /**
         * Create a custom logger.
         *
         * @return logger
         */
        public static function create($path, $name = 'default')
        {
            $pid       = getmypid();
            $output    = '[%datetime%] #' . $pid . " %level_name%: %message%\n";
            $formatter = new LineFormatter($output);

            $stream = new StreamHandler($path, Logger::INFO);
            $stream->setFormatter($formatter);

            $logger = new Logger($name);
            $logger->pushHandler($stream);

            return $logger;
        }

    }
