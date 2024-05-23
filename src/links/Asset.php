<?php
namespace verbb\hyper\links;

use verbb\hyper\base\ElementLink;

use Craft;
use craft\elements\Asset as AssetElement;

class Asset extends ElementLink
{
    // Static Methods
    // =========================================================================

    public static function displayName(): string
    {
        return Craft::t('hyper', 'Asset');
    }

    public static function elementType(): string
    {
        return AssetElement::class;
    }

    public static function checkElementUri(): bool
    {
        return false;
    }
}
