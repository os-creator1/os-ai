<?php


    namespace App\Library;

    use App\Helpers\Helper;
    use Exception;
    use Symfony\Component\HttpFoundation\IpUtils;

    class StringHelper
    {
        /**
         * Custom base64 encoding. Replace unsafe url chars.
         *
         * @param $string
         *
         * @return string
         */
        public static function base64UrlEncode($string)
        {
            if (is_null($string)) {
                return null;
            }

            return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($string));
        }

        /**
         * Custom base64 decode. Replace custom url safe values with normal
         * base64 characters before decoding.
         *
         * @param $string
         *
         * @return string
         */
        public static function base64UrlDecode($string)
        {
            if (is_null($string)) {
                return null;
            }

            return base64_decode(str_replace(['-', '_'], ['+', '/'], $string));
        }

        /**
         * Custom base64 decode. Replace custom url safe values with normal
         * base64 characters before decoding.
         *
         * @param $msgId
         *
         * @return string
         */
        public static function cleanupMessageId($msgId)
        {
            return preg_replace('/[<>\s]*/', '', $msgId);
        }

        /**
         * Custom base64 decode. Replace custom url safe values with normal
         * base64 characters before decoding.
         *
         * @return string
         */
        public static function joinUrl()
        {
            $array = array_map(function ($e) {
                return preg_replace('/(^\/+|\/+$)/', '', $e);
            }, func_get_args());

            return implode('/', $array);
        }


        /**
         * Detect file encoding.
         *
         * @param string $file file path
         *
         * @return string encoding or false if cannot detect one
         */
        public static function detectEncoding(string $file, $max = 100)
        {
            $file = fopen($file, 'r');

            $sample = '';
            $count  = 0;
            while ( ! feof($file) && $count <= $max) {
                $count  += 1;
                $sample .= fgets($file);
            }
            fclose($file);

            return mb_detect_encoding($sample, 'UTF-8, ISO-8859-1', true);
        }

        /**
         * Convert from one encoding to the other.
         *
         * @param string $file file path
         */
        public static function toUTF8(string $file, $from = 'UTF-8')
        {
            $content = file_get_contents($file);
            $content = mb_convert_encoding($content, 'UTF-8', $from);
            file_put_contents($file, $content);
        }

        /**
         * Check if a (UTF-8 encoded) file contains BOM
         * Fix it (remove BOM chars) if any.
         *
         * @param string $file file path
         */
        public static function checkAndRemoveUTF8BOM(string $file)
        {
            $bom     = pack('H*', 'EFBBBF');
            $text    = file_get_contents($file);
            $matched = preg_match("/^$bom/", $text);

            if ( ! $matched) {
                return false;
            }

            $text = preg_replace("/^$bom/", '', $text);
            file_put_contents($file, $text);

            return true;
        }

        // Remove from string, use for email addresses
        public static function removeUTF8BOM($text)
        {
            $bom = pack('H*', 'EFBBBF');

            // Standard method
            $text = preg_replace("/^$bom/", '', $text);

            // More destructive method, as the first method may miss the following ones with more than one BOM:
            return str_replace("\xEF\xBB\xBF", '', $text);
        }

        public static function replaceBareLineFeed($content)
        {
            return trim(preg_replace("/(?<=[^\r])\n/", "\r\n", $content));
        }

        /**
         * @param $directory
         * @param $name
         * @return mixed|string
         * @throws Exception
         */
        public static function generateUniqueName($directory, $name)
        {
            $count   = 1;
            $path    = Helper::join_paths($directory, $name);
            $newName = $name;
            while (file_exists($path)) {
                $regxp = '/(?<ext>\.[^\/\.]+$)/';
                preg_match($regxp, $name, $matched);

                if (array_key_exists('ext', $matched)) {
                    $fileExt = $matched['ext'];
                } else {
                    $fileExt = '';
                }

                $base    = preg_replace($regxp, '', $name);
                $newName = $base . '_' . $count . $fileExt;
                $path    = Helper::join_paths($directory, $newName);
                $count   += 1;
            }

            return $newName;
        }

        public static function isTag($string)
        {
            return preg_match('/{[a-zA-Z0-9_]+}/', $string);
        }

        public static function fromHumanIpAddress($ipAddress)
        {
            $googleIpRanges = config('google');
            foreach ($googleIpRanges as $cidr) {
                if (IpUtils::checkIp($ipAddress, $cidr)) {
                    return false;
                }
            }

            return true;
        }

        public static function removeExtraCharacters($phone)
        {
            return str_replace(['+', '-', '(', ')', ' '], '', $phone);
        }

    }

