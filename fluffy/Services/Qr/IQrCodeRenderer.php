<?php

namespace Fluffy\Services\Qr;

/**
 * Draws a QR code as SVG markup. Core has no encoder of its own; an app that has one registers
 * it under this interface, and features that show a QR code (two-factor setup) use it when it
 * is there and fall back to text when it is not.
 */
interface IQrCodeRenderer
{
    public function toSvg(string $data, int $border = 4): string;
}
