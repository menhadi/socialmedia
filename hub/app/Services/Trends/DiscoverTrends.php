<?php

namespace App\Services\Trends;

use App\Services\Research\FetchSource;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

class DiscoverTrends
{
    public function __construct(private FetchSource $reader) {}

    public function run(array $settings): array
    {
        $feeds = ['https://trends.google.com/trending/rss?geo='.$settings['region']];
        if ($settings['feed_url'] ?? null) {
            $feeds[] = $settings['feed_url'];
        }
        $candidates = [];
        $errors = [];
        foreach ($feeds as $url) {
            try {
                $candidates = array_merge($candidates, $this->parse($this->reader->feed($url), $url));
            } catch (\Throwable) {
                $errors[] = 'Feed unavailable: '.$url;
            }
        }
        usort($candidates, fn (array $a, array $b) => strcmp($b['published_at'], $a['published_at']));
        $seen = [];
        $keywords = array_filter(array_map('trim', explode(',', $settings['keywords'])));
        $candidates = array_values(array_filter($candidates, function (array $candidate) use (&$seen, $keywords): bool {
            $key = mb_strtolower(FetchSource::normalize($candidate['topic']));
            if (isset($seen[$key])) {
                return false;
            }
            $text = mb_strtolower($candidate['topic'].' '.$candidate['summary']);
            if (! array_any($keywords, fn (string $keyword) => str_contains($text, mb_strtolower($keyword)))) {
                return false;
            }
            $seen[$key] = true;

            return true;
        }));

        return ['candidates' => array_slice($candidates, 0, 20), 'errors' => $errors, 'retrieved_at' => now()->toIso8601String()];
    }

    private function parse(string $xml, string $feedUrl): array
    {
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new RuntimeException('Feed entities are not supported.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new DOMDocument;
            if (! $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                throw new RuntimeException('Unreadable feed.');
            }
            $xpath = new DOMXPath($doc);
            $items = $xpath->query('//*[local-name()="item" or local-name()="entry"]');
            $result = [];
            foreach ($items as $item) {
                $read = fn (string $name): string => FetchSource::normalize($xpath->evaluate('string(./*[local-name()="'.$name.'"])', $item));
                $dateText = $read('pubDate') ?: ($read('published') ?: $read('updated'));
                if ($dateText === '') {
                    continue;
                }
                try {
                    $date = CarbonImmutable::parse($dateText)->utc();
                } catch (\Throwable) {
                    continue;
                }
                if ($date->lt(now('UTC')->subHours(48)) || $date->gt(now('UTC')->addMinutes(5))) {
                    continue;
                }
                $topic = $read('title');
                $link = $read('link');
                foreach ($xpath->query('./*[local-name()="link"]', $item) as $node) {
                    if ($node instanceof DOMElement && $node->getAttribute('href') && in_array($node->getAttribute('rel'), ['', 'alternate'], true)) {
                        $link = $node->getAttribute('href');
                        break;
                    }
                }
                $newsUrl = $xpath->evaluate('string(./*[local-name()="news_item"]/*[local-name()="news_item_url"])', $item);
                $link = $newsUrl ?: $link;
                if (! $topic || strlen($link) > 2048 || ! filter_var($link, FILTER_VALIDATE_URL) || ! in_array(parse_url($link, PHP_URL_SCHEME), ['http', 'https'], true)) {
                    continue;
                }
                $traffic = $read('approx_traffic');
                $summary = strip_tags($read('description') ?: $read('summary'));
                $summary .= ' '.$xpath->evaluate('string(./*[local-name()="news_item"]/*[local-name()="news_item_title"])', $item);
                $result[] = [
                    'topic' => mb_substr($topic, 0, 200), 'url' => $link,
                    'summary' => mb_substr(FetchSource::normalize(html_entity_decode($summary, ENT_QUOTES | ENT_HTML5, 'UTF-8')), 0, 700),
                    'signal' => $traffic && parse_url($feedUrl, PHP_URL_HOST) === 'trends.google.com' ? 'Google search trend' : 'Recent feed topic (popularity unverified)',
                    'traffic' => mb_substr($traffic, 0, 50), 'published_at' => $date->toIso8601String(), 'feed_url' => $feedUrl,
                ];
                if (count($result) >= 200) {
                    break;
                }
            }

            return $result;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
