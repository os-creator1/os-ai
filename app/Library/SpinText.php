<?php


namespace App\Library;


class SpinText
{

    /**
     * process the data
     *
     * @param $text
     *
     * @return array|string|string[]|null
     */
    public function process($text)
    {
        $spun_txt = preg_replace_callback(
                '/\{(((?>[^\{\}]+)|(?R))*?)\}/x',
                [$this, 'replace'],
                $text
        );
        //return $spun_txt;
        //text has been spun, now try spinning numbers?
        return $this->process_nums($spun_txt);
    }

    /**
     *
     *
     * @param $text
     *
     * @return array|string|string[]|null
     */
    private function process_nums($text)
    {
        return preg_replace_callback(
                '/\[\d+\-\d+\]/x',
                [$this, 'replace_nums'],
                $text
        );
    }

    private function replace($text)
    {
        $text  = $this->process($text[1]);
        $parts = explode('|', $text);

        return $parts[array_rand($parts)];
    }

    private function replace_nums($text)
    {
        //first let's remove the brackets...
        $text = str_replace(['[', ']'], '', $text);
        //maybe we dont need to process again?
        //$text = $this->process_nums($text[1]);
        $text  = $text[0];
        $parts = explode('-', $text);
        //return $parts;
        //more KJ validation just to be safe..
        if (is_numeric($parts[0]) && is_numeric($parts[1])) {
            //both nums are numeric, proceed with rand generation
            return rand($parts[0], $parts[1]);
        }

        return $parts[array_rand($parts)];
    }
}

/*
*
*        EXAMPLE:
*
*        $new_sms_msg = $SpinText->process($old_sms_msg);
*
*        An example string:  {hi|hello|sup|greetings}, I like the color {blue|red|green|orange} the best, and I like the number [1-20] the most, but like number [300-500] the least!
*
*        Would RETURN something like: hello, I like the color green the best, and I like the number 13 the most, but like number 493 the least!
*
*        OR: sup, I like the color blue the best, and I like the number 7 the most, but like number 336 the least!
*/
