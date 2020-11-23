<?php declare(strict_types=1);

namespace SearchSolr\ValueFormatter;

/**
 * Formatter that includes only uris, mainly for value with data type uri.
 */
class UriOnly implements ValueFormatterInterface
{
    public function getLabel(): string
    {
        return 'Uri only'; // @translate
    }

    public function format($value)
    {
        if (is_object($value) && $value instanceof \Omeka\Api\Representation\ValueRepresentation) {
            $value = $value->uri();
        }
        return filter_var((string) $value, FILTER_VALIDATE_URL) ?: '';
    }
}