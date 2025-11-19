<?php

declare(strict_types=1);

namespace openvk\Web\Models\Utils;

use HTMLPurifier;
use HTMLPurifier_Config;
use openvk\Web\Models\Entities\Club;
use function tr;

final class WikiParser
{
    private HTMLPurifier $purifier;

    public function __construct()
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Doctype', 'XHTML 1.1');
        $config->set('Cache.DefinitionImpl', null);
        // We'll handle line breaks ourselves for VK-like behavior
        $config->set('AutoFormat.AutoParagraph', false);
        $config->set('AutoFormat.RemoveEmpty', true);
        $config->set('AutoFormat.Linkify', true);
        $config->set('URI.Munge', '/away.php?xinf=%n.%m:%r&css=%p&to=%s');
        $config->set('URI.MakeAbsolute', true);
        $config->set('Attr.AllowedClasses', [
            'text_gray','align_center','align_right','spoiler','wiki-table',
            'wiki-embed','wiki-embed-photo','wiki-embed-video','wiki-embed-audio',
            'wiki-image','wiki-spoiler','wiki-spoiler__toggle','wiki-spoiler__content',
            'wiki-code','wiki-code__inner'
        ]);
        $config->set('HTML.AllowedElements', [
            'h2','h3','h4','h5','h6','p','b','i','s','u','strong','em','sup','sub','ul','ol','li','blockquote','a','table','thead','tbody','tr','td','th','hr','br','span','div','code','pre','img','button'
        ]);
        $config->set('HTML.AllowedAttributes', [
            'a.href','a.rel','a.target','span.class','div.class','span.style','div.style','table.class','th.colspan','td.colspan','td.rowspan','th.rowspan',
            'img.src','img.width','img.height','img.alt','img.class','img.style',
            'h2.id','h3.id','h4.id','h5.id','h6.id',
            'button.type','button.class','button.data-hide',
            'pre.class','code.class'
        ]);
        $this->purifier = new HTMLPurifier($config);
    }

    public function render(string $source, Club $club): string
    {
        $text = \str_replace(["\r\n", "\r"], "\n", $source);

        // Remove {{notoc}} directive before further processing
        $text = preg_replace('/\{\{\s*notoc\s*\}\}/i', '', $text);

        // Headings: == H2 == to h2, etc. with anchors
        $usedIds = [];
        // VK-style headings: exactly N '=' on both sides map to hN (N=2..6)
        for ($level = 6; $level >= 2; $level--) {
            $eq = str_repeat('=', $level);
            $pattern = '/^' . preg_quote($eq, '/') . '\s*(.+?)\s*' . preg_quote($eq, '/') . '\s*$/m';
            $text = preg_replace_callback($pattern, function($m) use (&$usedIds, $level) {
                $raw = trim($m[1]);
                $id = preg_replace('~[^A-Za-z0-9._-]+~', '-', mb_strtolower($raw));
                $id = trim($id, '-');
                if ($id === '') { $id = 'h'; }
                $base = $id; $i = 2;
                while (in_array($id, $usedIds, true)) { $id = $base . '-' . $i++; }
                $usedIds[] = $id;
                return '<h' . $level . ' id="' . $id . '">' . htmlspecialchars($raw) . '</h' . $level . '>';
            }, $text);

        }

        // Image embed: [[img:URL|w=200|h=150|align=center]]
        $text = preg_replace_callback('/\[\[img:\s*([^|\]\s]+)\s*(?:\|\s*([^\]]+))?\]\]/i', function($m){
            $url = $m[1];
            $params = isset($m[2]) ? explode('|', $m[2]) : [];
            $w = null; $h = null; $align = null;
            foreach ($params as $p) {
                $p = trim($p);
                if (preg_match('/^w\s*=\s*(\d{1,4})$/i', $p, $mm)) { $w = max(1, min(1200, (int)$mm[1])); continue; }
                if (preg_match('/^h\s*=\s*(\d{1,4})$/i', $p, $mm)) { $h = max(1, min(1200, (int)$mm[1])); continue; }
                if (preg_match('/^align\s*=\s*(left|right|center)$/i', $p, $mm)) { $align = strtolower($mm[1]); continue; }
            }
            $style = '';
            if (!is_null($w)) $style .= 'max-width:'.$w.'px;';
            if (!is_null($h)) $style .= 'max-height:'.$h.'px;';
            $img = '<img class="wiki-image" src="' . htmlspecialchars($url, ENT_QUOTES) . '" alt=""' . (strlen($style)?' style="'.$style.'"':'') . '/>';
            if ($align === 'center') return '<div class="align_center">'.$img.'</div>';
            if ($align === 'right')  return '<div class="align_right">'.$img.'</div>';
            return $img;
        }, $text);

        // Map <tt>..</tt> to <code>..</code>
        $text = preg_replace('/<\s*tt\s*>(.*?)<\s*\/\s*tt\s*>/is', '<code>$1</code>', $text);

        // Custom tags: <gray>, <center>, <right>
        $text = preg_replace('/<\s*gray\s*>(.*?)<\s*\/\s*gray\s*>/is', '<span class="text_gray" style="color:#777;">$1</span>', $text);
        $text = preg_replace('/<\s*center\s*>(.*?)<\s*\/\s*center\s*>/is', '<div class="align_center" style="text-align:center;">$1</div>', $text);
        $text = preg_replace('/<\s*right\s*>(.*?)<\s*\/\s*right\s*>/is', '<div class="align_right" style="text-align:right;">$1</div>', $text);

        // Bold '''text''' and italic ''text''
        $text = preg_replace("/'''(.*?)'''/s", '<b>$1</b>', $text);
        $text = preg_replace("/''(.*?)''/s", '<i>$1</i>', $text);

        // Lists: lines starting with * or #
        $text = $this->parseLists($text);

        // Blockquotes: lines starting with >
        $text = preg_replace('/^>\s?(.*)$/m', '<blockquote>$1</blockquote>', $text);

        // Spoiler: {{spoiler|text}}
        $showLabel = htmlspecialchars(tr('_spoiler_show'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $hideLabel = htmlspecialchars(tr('_spoiler_hide'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = preg_replace_callback('/\{\{\s*spoiler\s*\|\s*(.*?)\s*\}\}/is', function($m) use ($showLabel, $hideLabel){
            $inner = $m[1];
            return '<span class="wiki-spoiler"><button type="button" class="wiki-spoiler__toggle" data-hide="' . $hideLabel . '">' . $showLabel . '</button><span class="wiki-spoiler__content" style="display:none;">' . $inner . '</span></span>';
        }, $text);

        // Code block: {{code|optional params}}
        $text = preg_replace_callback('/\{\{\s*code\s*\|(.*?)\}\}/is', function($m){
            $payload = $m[1];
            $lang = null;
            $body = $payload;
            if (strpos($payload, '|') !== false) {
                [$first, $rest] = explode('|', $payload, 2);
                if (preg_match('/^\s*lang\s*=\s*([a-z0-9_\-\+\.]+)/i', $first, $langMatch)) {
                    $lang = strtolower($langMatch[1]);
                    $body = $rest;
                }
            }
            $code = htmlspecialchars($body, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $langClass = $lang ? ' lang-' . $lang : '';
            return '<pre class="wiki-code' . $langClass . '"><code class="wiki-code__inner' . $langClass . '">' . $code . '</code></pre>';
        }, $text);

        // Media embeds: [[photo|video|audio-OWNER_ID_ID|Label]]
        // OWNER_ID format: <owner>_<id>, owner can be negative for clubs
        $text = preg_replace_callback('/\[\[(photo|video|audio)-?(-?\d+)_([0-9]+)(?:\|(.*?))?\]\]/i', function($m){
            $type = strtolower($m[1]);
            $owner = $m[2];
            $id = $m[3];
            $label = isset($m[4]) && $m[4] !== '' ? $m[4] : null;
            switch($type){
                case 'photo':
                    $url = '/photo' . $owner . '_' . $id; $text = $label ?? 'Фото';
                    return '<div class="wiki-embed wiki-embed-photo"><a href="' . htmlspecialchars($url, ENT_QUOTES) . '">📷 ' . htmlspecialchars($text) . '</a></div>';
                case 'video':
                    $url = '/video' . $owner . '_' . $id; $text = $label ?? 'Видео';
                    return '<div class="wiki-embed wiki-embed-video"><a href="' . htmlspecialchars($url, ENT_QUOTES) . '">▶ ' . htmlspecialchars($text) . '</a></div>';
                case 'audio':
                    $url = '/audio' . $owner . '_' . $id; $text = $label ?? 'Аудиозапись';
                    return '<div class="wiki-embed wiki-embed-audio"><a href="' . htmlspecialchars($url, ENT_QUOTES) . '">🎵 ' . htmlspecialchars($text) . '</a></div>';
                default:
                    return $m[0];
            }
        }, $text);

        // Internal links [[slug|Text]] or [[slug]], but NOT media tokens
        $text = preg_replace_callback('/\[\[(?!\s*(?:photo|video|audio)-?)(.+?)\]\]/i', function ($m) use ($club) {
            $parts = explode('|', $m[1], 2);
            $slug = trim($parts[0]);
            $label = isset($parts[1]) ? trim($parts[1]) : $slug;
            // Slug sanitize
            $slug = preg_replace('~[^A-Za-z0-9._-]~', '-', $slug);
            $href = $club->getURL() . '/wiki/' . $slug;
            return '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '">' . htmlspecialchars($label) . '</a>';
        }, $text);

        // Horizontal rule: ----
        $text = preg_replace('/^-{4,}\s*$/m', '<hr/>', $text);

        // Simple tables: {| ... |}
        $text = $this->parseTables($text);

        // Normalize excessive blank lines
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        // Convert remaining single newlines to <br/>
        $text = nl2br($text);
        $text = str_replace('<br />', '<br/>', $text);

        // Clean line breaks inside code blocks
        $text = preg_replace_callback('/<pre\b[^>]*>.*?<\/pre>/is', function($m){
            $segment = $m[0];
            $segment = str_replace(['<br/>', '<br />'], "\n", $segment);
            return $segment;
        }, $text);

        // Cleanup: remove stray <br/> around block elements
        $block = '(?:h[2-6]|p|div|blockquote|ul|ol|li|table|thead|tbody|tr|td|th|hr)';
        // <tag> <br/> -> <tag>
        $text = preg_replace('/(<'.$block.'[^>]*>)\s*<br\s*\/?>(?=\s*)/i', '$1', $text);
        // <br/> </tag> -> </tag>
        $text = preg_replace('/<br\s*\/?>(?=\s*<\/'.$block.'>)/i', '', $text);

        // Purify final HTML
        return $this->purifier->purify($text);
    }

    private function parseLists(string $text): string
    {
        $lines = explode("\n", $text);
        $out = [];
        $stack = [];
        foreach ($lines as $line) {
            if (preg_match('/^([*#]+)\s*(.*)$/', $line, $m)) {
                $markers = $m[1];
                $content = $m[2];
                $level = strlen($markers);
                $type = substr($markers, -1) === '#' ? 'ol' : 'ul';
                // Close/open lists to match level and type
                while (!empty($stack) && (count($stack) > $level || end($stack) !== $type)) {
                    $out[] = '</li></' . array_pop($stack) . '>';
                }
                while (count($stack) < $level) {
                    $stack[] = $type;
                    $out[] = '<' . $type . '><li>';
                }
                if (count($stack) === $level && !empty($out) && substr(end($out), 0, 4) !== '<ul>' && substr(end($out), 0, 4) !== '<ol>') {
                    $out[] = '</li><li>' . htmlspecialchars($content);
                } else {
                    $out[] = htmlspecialchars($content);
                }
            } else {
                while (!empty($stack)) {
                    $out[] = '</li></' . array_pop($stack) . '>';
                }
                $out[] = $line;
            }
        }
        while (!empty($stack)) {
            $out[] = '</li></' . array_pop($stack) . '>';
        }
        return implode("\n", $out);
    }

    private function parseTables(string $text): string
    {
        // Very minimal: replace lines starting with {| to <table>, |- row sep, |} end, | cell
        $text = preg_replace('/^\{\|.*$/m', '<table class="wiki-table">', $text);
        $text = preg_replace('/^\|\-.*$/m', '<tr>', $text);
        $text = preg_replace('/^\|\}.*$/m', '</table>', $text);
        // Cells: "| a || b" -> <td>a</td><td>b</td>
        $text = preg_replace_callback('/^\|\s*(.+)$/m', function ($m) {
            $cells = preg_split('/\s*\|\|\s*/', $m[1]);
            $html = '<tr>';
            foreach ($cells as $c) {
                $html .= '<td>' . htmlspecialchars($c) . '</td>';
            }
            $html .= '</tr>';
            return $html;
        }, $text);
        return $text;
    }
}
