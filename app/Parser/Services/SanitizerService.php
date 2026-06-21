<?php

namespace App\Parser\Services;

use App\Models\Parser\Court;
use App\Models\Parser\RawPage;
use App\Parser\DTO\FetchResponse;
use App\Parser\Support\Html;
use DOMElement;
use Illuminate\Support\Facades\Storage;

class SanitizerService
{
    private const RU_PARTIES_TITLE = "\u{0441}\u{0442}\u{043e}\u{0440}\u{043e}\u{043d}\u{044b} \u{043f}\u{043e} \u{0434}\u{0435}\u{043b}\u{0443}";

    private const RU_PLAINTIFF = "\u{0438}\u{0441}\u{0442}\u{0435}\u{0446}";

    private const RU_DEFENDANT = "\u{043e}\u{0442}\u{0432}\u{0435}\u{0442}\u{0447}\u{0438}\u{043a}";

    private const RU_THIRD_PARTY = "\u{0442}\u{0440}\u{0435}\u{0442}\u{044c}\u{0435} \u{043b}\u{0438}\u{0446}\u{043e}";

    public function rememberFetchedPage(Court $court, FetchResponse $response, string $pageType): RawPage
    {
        $sanitizedPath = null;

        if ($pageType === 'case') {
            $sanitized = $this->sanitizeCaseHtml($response->body);
            $sanitizedPath = 'parser/raw_pages/'.now()->format('Y/m/d').'/'.$response->contentHash.'.html';
            Storage::disk('local')->put($sanitizedPath, $sanitized);
        }

        return RawPage::query()->updateOrCreate(
            [
                'url_hash' => hash('sha256', $response->url),
                'content_hash' => $response->contentHash,
            ],
            [
                'court_id' => $court->id,
                'url' => $response->url,
                'page_type' => $pageType,
                'fetched_at' => now(),
                'http_status' => $response->statusCode,
                'sanitized_html_path' => $sanitizedPath,
                'parser_version' => config('parser.version'),
            ],
        );
    }

    public function sanitizeCaseHtml(string $html): string
    {
        $xpath = Html::xpath($html);

        foreach ($xpath->query('//*[@id="cont3" or @id="tab3"]') as $node) {
            $node->parentNode?->removeChild($node);
        }

        foreach ($xpath->query('//table | //tr') as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $text = mb_strtolower(Html::text($node));
            if ($this->looksLikePartiesNode($text)) {
                $node->parentNode?->removeChild($node);
            }
        }

        $html = $xpath->document->saveHTML() ?: '';

        return html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function looksLikePartiesNode(string $text): bool
    {
        if (str_contains($text, self::RU_PARTIES_TITLE)) {
            return true;
        }

        return str_contains($text, self::RU_PLAINTIFF)
            || str_contains($text, self::RU_DEFENDANT)
            || str_contains($text, self::RU_THIRD_PARTY)
            || str_contains($text, 'plaintiff')
            || str_contains($text, 'defendant')
            || str_contains($text, 'third party');
    }
}
