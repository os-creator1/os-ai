<?php

namespace Tests\Unit\Marketing;

use App\Library\Marketing\YoutubeUrlParser;
use PHPUnit\Framework\TestCase;

/**
 * Public Marketing Homepage contract — safe parsing of an admin-pasted
 * YouTube URL down to its 11-character video ID. Pure unit test: no
 * framework, no database.
 */
class YoutubeUrlParserTest extends TestCase
{
    public static function validYoutubeUrls(): array
    {
        return [
            'watch url' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'watch url without www' => ['https://youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'watch url with extra query params' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=30s&list=PL123', 'dQw4w9WgXcQ'],
            'short url' => ['https://youtu.be/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'short url with query params' => ['https://youtu.be/dQw4w9WgXcQ?t=15', 'dQw4w9WgXcQ'],
            'embed url' => ['https://www.youtube.com/embed/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'nocookie embed url' => ['https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'shorts url' => ['https://www.youtube.com/shorts/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'mobile url' => ['https://m.youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'id containing dash and underscore' => ['https://youtu.be/a_B-c_D-e_F', 'a_B-c_D-e_F'],
        ];
    }

    /** @dataProvider validYoutubeUrls */
    public function test_it_extracts_the_video_id_from_recognized_youtube_url_shapes(string $url, string $expectedId): void
    {
        $this->assertTrue(YoutubeUrlParser::isYoutubeUrl($url));
        $this->assertSame($expectedId, YoutubeUrlParser::extractVideoId($url));
    }

    public static function invalidYoutubeUrls(): array
    {
        return [
            'watch url missing v param' => ['https://www.youtube.com/watch?list=PL123'],
            'watch url with too-short id' => ['https://www.youtube.com/watch?v=short'],
            'watch url with too-long id' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQextra'],
            'watch url with unsafe characters in id' => ['https://www.youtube.com/watch?v=dQw4w9<script>'],
            'youtube.com with no video reference at all' => ['https://www.youtube.com/'],
            'youtu.be with empty path' => ['https://youtu.be/'],
        ];
    }

    /** @dataProvider invalidYoutubeUrls */
    public function test_it_refuses_to_extract_an_id_from_a_malformed_youtube_url(string $url): void
    {
        $this->assertTrue(YoutubeUrlParser::isYoutubeUrl($url), 'Expected the host to still be recognized as YouTube.');
        $this->assertNull(YoutubeUrlParser::extractVideoId($url));
    }

    public static function nonYoutubeUrls(): array
    {
        return [
            'vimeo' => ['https://vimeo.com/123456789'],
            'direct video file' => ['https://videos.example.test/feedback.mp4'],
            'a host that merely contains youtube as a substring' => ['https://notyoutube.com/watch?v=dQw4w9WgXcQ'],
            'a spoofed host with youtube.com as a suffix of another domain' => ['https://evil-youtube.com/watch?v=dQw4w9WgXcQ'],
        ];
    }

    /** @dataProvider nonYoutubeUrls */
    public function test_it_does_not_treat_an_unrelated_or_spoofed_host_as_youtube(string $url): void
    {
        $this->assertFalse(YoutubeUrlParser::isYoutubeUrl($url));
        $this->assertNull(YoutubeUrlParser::extractVideoId($url));
    }

    public function test_thumbnail_and_embed_urls_are_built_from_the_id_only(): void
    {
        $this->assertSame(
            'https://img.youtube.com/vi/dQw4w9WgXcQ/hqdefault.jpg',
            YoutubeUrlParser::thumbnailUrl('dQw4w9WgXcQ'),
        );

        $embed = YoutubeUrlParser::noCookieEmbedUrl('dQw4w9WgXcQ');
        $this->assertStringStartsWith('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $embed);
        $this->assertStringNotContainsString('youtube.com/embed', str_replace('youtube-nocookie.com', '', $embed));
    }
}
