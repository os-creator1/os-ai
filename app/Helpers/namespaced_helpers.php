<?php

    namespace App\Helpers;

    use App\Library\Exception\RateLimitExceeded;
    use App\Library\StringHelper;
    use Artisan;
    use Closure;
    use Exception;
    use File;
    use Monolog\Formatter\LineFormatter;
    use Monolog\Handler\RotatingFileHandler;
    use Monolog\Logger;
    use SimpleXMLElement;
    use Throwable;

// Get application host with {scheme}://{host}:{port} (without subdirectory)
    /**
     * @throws Exception
     */
    function getAppHost()
    {
        $fullUrl = config('app.url');
        $meta    = parse_url($fullUrl);

        if ( ! array_key_exists('scheme', $meta) || ! array_key_exists('host', $meta)) {
            throw new Exception('Invalid app.url setting');
        }

        $appHost = "{$meta['scheme']}://{$meta['host']}";

        if (array_key_exists('port', $meta)) {
            $appHost = "{$appHost}:{$meta['port']}";
        }

        return $appHost;
    }

    function ptouch($filepath)
    {
        $dirname = dirname($filepath);
        if ( ! File::exists($dirname)) {
            File::makeDirectory($dirname, 0777, true, true);
        }

        touch($filepath);
    }

    function xml_to_array(SimpleXMLElement $xml)
    {
        $parser = function (SimpleXMLElement $xml, array $collection = []) use (&$parser) {
            $nodes      = $xml->children();
            $attributes = $xml->attributes();

            if (count($attributes) !== 0) {
                foreach ($attributes as $attrName => $attrValue) {
                    $collection['attributes'][$attrName] = html_entity_decode(strval($attrValue));
                }
            }

            if ($nodes->count() === 0) {
                // $collection['value'] = stream($xml);
                // return $collection;
                return html_entity_decode(strval($xml));
            }

            foreach ($nodes as $nodeName => $nodeValue) {
                if (count($nodeValue->xpath('../' . $nodeName)) < 2) {
                    $collection[$nodeName] = $parser($nodeValue);

                    continue;
                }

                $collection[$nodeName][] = $parser($nodeValue);
            }

            return $collection;
        };

        return [
            $xml->getName() => $parser($xml),
        ];
    }

    function write_env($key, $value, $overwrite = true)
    {
        // Important, make the new environment var available
        // Otherwise, this method may failed if called twice (in a loop for example) in the same process
        Artisan::call('config:clear');

        // In case config:clear does not work
        if (file_exists(base_path('bootstrap/cache/config.php'))) {
            unlink(base_path('bootstrap/cache/config.php'));
        }

        $envs = load_env_from_file(app()->environmentFilePath());

        // Set the value if overwrite is set to true or the key value is empty
        if ($overwrite || ! array_key_exists($key, $envs) || empty($envs[$key])) {
            $envs[$key] = format_dotenv_value($value);
        } else {
            return;
        }

        $out = [];
        foreach ($envs as $k => $v) {
            $out[] = "$k=$v";
        }

        $out = implode("\n", $out);

        // Actually write to file .env
        file_put_contents(app()->environmentFilePath(), $out);
    }

    /**
     * B3 Simplified Platform Settings hardening. Quote and escape a raw
     * scalar value for safe storage as exactly one .env "KEY=value" line.
     * Always quotes -- unlike write_env()'s previous behavior, which only
     * quoted when the value happened to contain a space/#/!/$, and even
     * then only escaped an embedded double quote, never an embedded
     * backslash or a real newline. Because load_env_from_file()/write_env()
     * key values by an exact, associative match (never a substring search),
     * this closes the remaining gap: a value can no longer end its quoted
     * string early (embedded ") or start what looks like a second physical
     * line (an embedded real newline), regardless of which .env key it is
     * written to.
     */
    function format_dotenv_value($value): string
    {
        $value = (string) $value;
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace('"', '\\"', $value);
        $value = str_replace(["\r\n", "\n", "\r"], '\\n', $value);

        return '"' . $value . '"';
    }

    function write_envs($params)
    {
        foreach ($params as $key => $value) {
            write_env($key, $value);
        }
    }

    function reset_app_url($force = false)
    {
        $envs = load_env_from_file(app()->environmentFilePath());
        if ( ! array_key_exists('APP_URL', $envs) || $force) {
            $url = url('/');
            write_env('APP_URL', $url);
        }
    }

// IMPORTANT
// + This function does not purify values, it will load raw content like: [ DB => "'mydb'", OTHER => '""']
// + Allow only a-zA-Z_ in key name
    function load_env_from_file($path)
    {
        // B3 Simplified Platform Settings hardening -- this helper had
        // zero live callers before B3 (confirmed by repo-wide search), so
        // its two bugs were dormant: array_where() is not a Laravel
        // helper (fatal "call to undefined function" on any real call),
        // and file_get_contents() against a missing .env (this
        // repository's own real test/CI gate runs with no .env file at
        // all -- Laravel's own LoadEnvironmentVariables::safeLoad()
        // already tolerates that at boot, config coming entirely from
        // process-level environment variables) would warn and return
        // false, which preg_split() cannot accept. A missing/unreadable
        // file is now treated as an empty environment; write_env() then
        // creates the file fresh on its first write.
        if (! is_file($path)) {
            return [];
        }

        $content = file_get_contents($path);

        if ($content === false) {
            return [];
        }

        $lines = preg_split("/(\r\n|\n|\r)/", $content);
        $lines = array_filter($lines, function ($value) {
            if (is_null($value)) {
                return false;
            }

            if (preg_match('/^[a-zA-Z0-9_]+=/', $value)) {
                return true;
            } else {
                return false;
            }
        });

        $output = [];
        foreach ($lines as $line) {
            [$key, $value] = explode('=', $line, 2);

            if (is_null($value)) {
                $value = '';
            } else {
                $value = trim($value);
            }

            $output[$key] = $value;
        }

        return $output;
    }

// Copy and:
// + Remove the destination first
// + Create parent folders if not exist
    /**
     * @throws Exception
     */
    function pcopy($src, $dst): void
    {
        if ( ! File::exists($src)) {
            throw new Exception("File `{$src}` does not exist");
        }

        if (File::exists($dst)) {
            // Delete the file or link or directory
            if (is_link($dst) || is_file($dst)) {
                File::delete($dst);
            } else {
                File::deleteDirectory($dst);
            }
        } else {
            // Make sure the PARENT directory exists
            $dirname = pathinfo($dst)['dirname'];
            if ( ! File::exists($dirname)) {
                File::makeDirectory($dirname, 0777, true, true);
            }
        }

        // if source is a file, just copy it
        if (File::isFile($src)) {
            File::copy($src, $dst);
        } else {
            File::copyDirectory($src, $dst);
        }
    }

    /**
     * @throws Exception
     */
    function plogger($name = null)
    {
        $formatter = new LineFormatter("[%datetime%] %channel%.%level_name%: %message%\n");
        $pid       = getmypid();
        $logfile   = storage_path(Helper::join_paths('logs', php_sapi_name(), '/process-' . $pid . '.log'));
        $stream    = new RotatingFileHandler($logfile, 0, config('custom.log_level'));
        $stream->setFormatter($formatter);

        $logger = new Logger($name ?: 'process');
        $logger->pushHandler($stream);

        return $logger;
    }

    /**
     * @throws Throwable
     * @throws RateLimitExceeded
     */
    function execute_with_limits(array $rateTrackers, Closure $task = null)
    {
        // Remove null tracker from array
        $rateTrackers = array_values(array_filter($rateTrackers));

        $rateCounted = [];
        try {
            foreach ($rateTrackers as $rateTracker) {
                $rateTracker->count();
                $rateCounted[] = $rateTracker;
            }
        } catch (RateLimitExceeded $exception) {

            // In case of more than one rate trackers
            // + Tracker A works just fine
            // + Tracker B works just fine
            // + Tracker C fails
            // Rollback tracker A and B
            foreach ($rateCounted as $rateTracker) {
                $rateTracker->rollback();
            }

            // This exception is safely handled in SendMessage ( i.e. catch (RateLimitExceeded $ex) )
            throw $exception;
        }


        // Return null if task is null, i.e. count credits but do not actually do anything
        if (is_null($task)) {
            return;
        }

        // Execute task
        $task();
    }
