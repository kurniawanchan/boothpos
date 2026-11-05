<?php

namespace App\Models\Concerns;

/** 058 — pasang pada model data yang dibatasi per seller; aturannya ada di SellerScope. */
trait HasSellerScope
{
    protected static function bootHasSellerScope(): void
    {
        static::addGlobalScope(new SellerScope);
    }
}
