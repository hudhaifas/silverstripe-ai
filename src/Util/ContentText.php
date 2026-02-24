<?php

namespace Hudhaifas\AI\Util;

/**
 * ContentText
 *
 * Pure text utilities for AI-generated content.
 * Handles sanitization and HTML rendering of plain-text content
 * that may contain legacy HTML artifacts.
 */
class ContentText {
    /**
     * Strip HTML tags and normalize whitespace for plain-text storage.
     * Handles legacy data that may contain literal <br> or other HTML tags.
     */
    public static function sanitize(string $content): string {
        $text = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
        $text = preg_replace('/<\/p>/i', "\n\n", $text);
        $text = strip_tags($text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return trim($text);
    }

    /**
     * Convert plain text (with \n\n paragraph breaks) to safe HTML <p> tags
     * for display in view mode. Handles legacy HTML-in-text data.
     */
    public static function toHtml(string $content): string {
        $plain = self::sanitize($content);
        if ($plain === '') {
            return '';
        }
        $paragraphs = preg_split("/\n\n+/", $plain);
        $html = '';
        foreach ($paragraphs as $para) {
            $para = trim($para);
            if ($para !== '') {
                $html .= '<p>' . nl2br(htmlspecialchars($para, ENT_QUOTES | ENT_HTML5, 'UTF-8')) . '</p>';
            }
        }
        return $html;
    }
}
