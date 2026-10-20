<?php

declare(strict_types=1);

namespace App\Service\Import\Confluence;

/**
 * Формат хранения Confluence (XHTML с элементами ac: и ri:) → HTML страницы портала.
 *
 * Обычная разметка (абзацы, заголовки, списки, таблицы, выделение) переносится как есть, макросы — по смыслу:
 * «код» → блок кода, «информация», «примечание», «внимание», «совет», «панель» → цитата с подписью, «раскрыть» →
 * заголовок и текст, «статус» → метка; оглавление, дочерние страницы и подобные — пропускаются. Изображения из
 * вложений встраиваются в страницу (data:), ссылки на страницы и вложения ведут на импортированные документы.
 * Итог очищается обычным фильтром страниц портала (DocumentManager::sanitize).
 */
final class StorageConverter
{
    private const AC = 'http://atlassian.com/content';
    private const RI = 'http://atlassian.com/resource/identifier';

    private const PASS = ['p', 'br', 'hr', 'h1', 'h2', 'h3', 'h4', 'strong', 'em', 'u', 's', 'sub', 'sup', 'ul', 'ol', 'li',
        'table', 'thead', 'tbody', 'tr', 'td', 'th', 'pre', 'code', 'blockquote', 'div', 'span'];
    private const RENAME = ['b' => 'strong', 'i' => 'em', 'del' => 's', 'strike' => 's', 'h5' => 'h4', 'h6' => 'h4', 'tfoot' => 'tbody', 'ins' => 'u', 'cite' => 'em', 'tt' => 'code', 'kbd' => 'code'];
    private const DROP = ['colgroup', 'col', 'script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'select', 'textarea'];
    private const VOID = ['br', 'hr', 'img'];

    private const PANELS = ['info' => 'Информация', 'note' => 'Примечание', 'warning' => 'Внимание', 'tip' => 'Совет', 'panel' => '', 'success' => 'Готово', 'error' => 'Ошибка'];
    private const SKIP_MACROS = ['toc', 'toc-zone', 'children', 'pagetree', 'pagetreesearch', 'recently-updated', 'contentbylabel', 'livesearch',
        'attachments', 'gallery', 'anchor', 'include', 'excerpt-include', 'blog-posts', 'create-from-template', 'contributors', 'page-index', 'popular-labels',
        'related-labels', 'listlabels', 'spaces', 'space-details', 'profile', 'tasks-report-macro', 'content-report-table', 'roadmap', 'widget', 'mention'];
    private const FILE_MACROS = ['view-file', 'viewfile', 'viewpdf', 'viewdoc', 'viewxls', 'viewppt', 'multimedia', 'pdf-viewer'];

    /** @var callable(string): ?string */
    private $image;
    /** @var callable(string, string): string */
    private $pageLink;
    /** @var callable(string): string */
    private $attachmentLink;
    private string $spaceKey = '';

    /**
     * @param callable(string): ?string         $image          data:-адрес встраиваемого изображения вложения (null — не встраивается)
     * @param callable(string, string): string $pageLink       адрес страницы (пространство, название)
     * @param callable(string): string         $attachmentLink адрес вложения этой страницы (имя файла)
     */
    public function convert(string $storage, string $spaceKey, callable $image, callable $pageLink, callable $attachmentLink): string
    {
        $this->image = $image;
        $this->pageLink = $pageLink;
        $this->attachmentLink = $attachmentLink;
        $this->spaceKey = $spaceKey;
        $root = self::parse($storage);
        if (null === $root) {
            // Разметка не разобралась как XML — переносится текст (без макросов).
            $text = trim(html_entity_decode(strip_tags((string) preg_replace('~<(br|/p|/h\d|/li|/tr)\b[^>]*>~i', "\n", $storage)), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));

            return '' === $text ? '' : implode('', array_map(static fn (string $line): string => '<p>'.htmlspecialchars($line, \ENT_QUOTES | \ENT_HTML5).'</p>', array_filter(array_map('trim', explode("\n", $text)), static fn (string $l): bool => '' !== $l)));
        }

        return trim($this->children($root));
    }

    /** Разбор фрагмента XHTML: именованные сущности HTML → числовые, пространства имён ac: и ri: объявлены. */
    public static function parse(string $storage): ?\DOMElement
    {
        $xml = (string) preg_replace('/<!DOCTYPE[^>]*>|<\?xml[^>]*\?>/i', '', $storage);
        $xml = (string) preg_replace_callback('/&([A-Za-z][A-Za-z0-9]{1,31});/', static function (array $m): string {
            if (\in_array($m[1], ['amp', 'lt', 'gt', 'quot', 'apos'], true)) {
                return $m[0];
            }
            $char = html_entity_decode($m[0], \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

            return $char === $m[0] ? '&amp;'.$m[1].';' : '&#'.mb_ord($char, 'UTF-8').';';
        }, $xml);
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $ok = $doc->loadXML('<root xmlns:ac="'.self::AC.'" xmlns:ri="'.self::RI.'" xmlns:at="http://atlassian.com/template">'.$xml.'</root>', \LIBXML_NONET | \LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $ok && $doc->documentElement instanceof \DOMElement ? $doc->documentElement : null;
    }

    private function children(\DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            $out .= $this->node($child);
        }

        return $out;
    }

    private function node(\DOMNode $node): string
    {
        if ($node instanceof \DOMText) { // и CDATA
            return self::e($node->nodeValue ?? '');
        }
        if (!$node instanceof \DOMElement) {
            return '';
        }
        if (self::AC === $node->namespaceURI) {
            return $this->ac($node);
        }
        if (self::RI === $node->namespaceURI) {
            return '';
        }
        $name = strtolower($node->localName ?? '');
        $name = self::RENAME[$name] ?? $name;
        if (\in_array($name, self::DROP, true)) {
            return '';
        }
        if ('a' === $name) {
            $href = trim($node->getAttribute('href'));
            $inner = $this->children($node);
            if (!preg_match('~^(https?:|mailto:|tel:|#)~i', $href)) {
                return $inner;
            }

            return '<a href="'.self::e($href).'">'.('' !== trim(strip_tags($inner)) ? $inner : self::e($href)).'</a>';
        }
        if ('img' === $name) {
            $src = trim($node->getAttribute('src'));

            return preg_match('~^https?://~i', $src) ? '<img src="'.self::e($src).'" alt="'.self::e($node->getAttribute('alt')).'">' : '';
        }
        if ('time' === $name) {
            $dt = $node->getAttribute('datetime');

            return self::e(preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $dt, $m) ? $m[3].'.'.$m[2].'.'.$m[1] : $node->textContent);
        }
        if (!\in_array($name, self::PASS, true)) {
            return $this->children($node);
        }
        $attrs = '';
        if (\in_array($name, ['td', 'th'], true)) {
            foreach (['colspan', 'rowspan'] as $a) {
                $v = (int) $node->getAttribute($a);
                if ($v > 1) {
                    $attrs .= ' '.$a.'="'.$v.'"';
                }
            }
        }
        if ('pre' === $name) {
            return '<pre><code>'.self::e($node->textContent).'</code></pre>';
        }

        return \in_array($name, self::VOID, true) ? '<'.$name.$attrs.'>' : '<'.$name.$attrs.'>'.$this->children($node).'</'.$name.'>';
    }

    private function ac(\DOMElement $el): string
    {
        switch ($el->localName) {
            case 'structured-macro':
            case 'macro':
                return $this->macro($el);
            case 'image':
                return $this->image($el);
            case 'link':
                return $this->link($el);
            case 'task-list':
                $items = '';
                foreach (self::childrenNamed($el, self::AC, 'task') as $task) {
                    $done = 'complete' === trim((string) self::childNamed($task, self::AC, 'task-status')?->textContent);
                    $body = self::childNamed($task, self::AC, 'task-body');
                    $items .= '<li>'.($done ? '☑ ' : '☐ ').(null !== $body ? $this->children($body) : '').'</li>';
                }

                return '<ul>'.$items.'</ul>';
            case 'emoticon':
                $fallback = $el->getAttributeNS(self::AC, 'emoji-fallback');

                return '' !== $fallback && !str_starts_with($fallback, ':') ? self::e($fallback) : '';
            case 'placeholder':
            case 'parameter':
                return '';
            case 'plain-text-body':
                return self::e($el->textContent);
            default:
                // layout, layout-section, layout-cell, rich-text-body, inline-comment-marker, adf-* — содержимое как есть.
                return $this->children($el);
        }
    }

    private function macro(\DOMElement $el): string
    {
        $name = strtolower($el->getAttributeNS(self::AC, 'name') ?: $el->getAttribute('ac:name'));
        $params = [];
        foreach (self::childrenNamed($el, self::AC, 'parameter') as $p) {
            $params[strtolower($p->getAttributeNS(self::AC, 'name'))] = $p;
        }
        $param = static fn (string $key): string => isset($params[$key]) ? trim($params[$key]->textContent) : '';
        $body = self::childNamed($el, self::AC, 'rich-text-body');
        $rich = null !== $body ? $this->children($body) : '';
        if (\in_array($name, ['code', 'noformat', 'code-block'], true)) {
            $text = (string) self::childNamed($el, self::AC, 'plain-text-body')?->textContent;
            $title = $param('title');

            return ('' !== $title ? '<p><strong>'.self::e($title).'</strong></p>' : '').'<pre><code>'.self::e($text).'</code></pre>';
        }
        if (isset(self::PANELS[$name])) {
            $title = $param('title') ?: self::PANELS[$name];

            return '<blockquote>'.('' !== $title ? '<p><strong>'.self::e($title).'</strong></p>' : '').$rich.'</blockquote>';
        }
        if ('expand' === $name || 'ui-expand' === $name) {
            return '<p><strong>'.self::e($param('title') ?: 'Подробнее').'</strong></p>'.$rich;
        }
        if ('status' === $name) {
            return '<strong>['.self::e($param('title') ?: $param('colour') ?: 'статус').']</strong>';
        }
        if ('jira' === $name) {
            $key = $param('key');

            return '' !== $key ? '<strong>'.self::e($key).'</strong>' : '';
        }
        if (\in_array($name, self::FILE_MACROS, true)) {
            $file = isset($params['name']) ? self::childNamed($params['name'], self::RI, 'attachment')?->getAttributeNS(self::RI, 'filename') : null;
            $file ??= $param('name');

            return '' !== (string) $file ? '<p><a href="'.self::e(($this->attachmentLink)((string) $file)).'">'.self::e((string) $file).'</a></p>' : '';
        }
        if (\in_array($name, self::SKIP_MACROS, true)) {
            return '';
        }
        if (null !== $body) {
            return $rich; // excerpt, section, column, details, panel-подобные макросы — содержимое
        }
        $plain = self::childNamed($el, self::AC, 'plain-text-body');

        return null !== $plain && '' !== trim($plain->textContent) ? '<pre><code>'.self::e($plain->textContent).'</code></pre>' : '';
    }

    private function image(\DOMElement $el): string
    {
        $alt = $el->getAttributeNS(self::AC, 'alt') ?: $el->getAttributeNS(self::AC, 'title');
        $width = (int) $el->getAttributeNS(self::AC, 'width');
        $size = $width > 0 ? ' width="'.min($width, 2000).'"' : '';
        $attachment = self::childNamed($el, self::RI, 'attachment');
        if (null !== $attachment) {
            $file = $attachment->getAttributeNS(self::RI, 'filename');
            $data = ($this->image)($file);
            if (null !== $data) {
                return '<img src="'.self::e($data).'" alt="'.self::e($alt ?: $file).'"'.$size.'>';
            }

            return '<a href="'.self::e(($this->attachmentLink)($file)).'">'.self::e($alt ?: $file).'</a>';
        }
        $url = self::childNamed($el, self::RI, 'url')?->getAttributeNS(self::RI, 'value') ?? '';
        if (preg_match('~^https?://~i', $url)) {
            return '<img src="'.self::e($url).'" alt="'.self::e($alt).'"'.$size.'>';
        }

        return '';
    }

    private function link(\DOMElement $el): string
    {
        $text = '';
        $plain = self::childNamed($el, self::AC, 'plain-text-link-body');
        $rich = self::childNamed($el, self::AC, 'link-body');
        if (null !== $plain) {
            $text = self::e(trim($plain->textContent));
        } elseif (null !== $rich) {
            $text = $this->children($rich);
        }
        $page = self::childNamed($el, self::RI, 'page');
        if (null !== $page) {
            $title = $page->getAttributeNS(self::RI, 'content-title');
            $space = $page->getAttributeNS(self::RI, 'space-key') ?: $this->spaceKey;

            return '<a href="'.self::e(($this->pageLink)($space, $title)).'">'.('' !== trim(strip_tags($text)) ? $text : self::e($title)).'</a>';
        }
        $attachment = self::childNamed($el, self::RI, 'attachment');
        if (null !== $attachment) {
            $file = $attachment->getAttributeNS(self::RI, 'filename');

            return '<a href="'.self::e(($this->attachmentLink)($file)).'">'.('' !== trim(strip_tags($text)) ? $text : self::e($file)).'</a>';
        }
        $url = self::childNamed($el, self::RI, 'url')?->getAttributeNS(self::RI, 'value') ?? '';
        if (preg_match('~^https?://~i', $url)) {
            return '<a href="'.self::e($url).'">'.('' !== trim(strip_tags($text)) ? $text : self::e($url)).'</a>';
        }
        $user = self::childNamed($el, self::RI, 'user');
        if (null !== $user) {
            return '' !== trim(strip_tags($text)) ? $text : '@пользователь';
        }

        return $text;
    }

    private static function childNamed(\DOMElement $el, string $ns, string $local): ?\DOMElement
    {
        foreach ($el->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->namespaceURI === $ns && $child->localName === $local) {
                return $child;
            }
        }

        return null;
    }

    /** @return list<\DOMElement> */
    private static function childrenNamed(\DOMElement $el, string $ns, string $local): array
    {
        $out = [];
        foreach ($el->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->namespaceURI === $ns && $child->localName === $local) {
                $out[] = $child;
            }
        }

        return $out;
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5, 'UTF-8');
    }
}
