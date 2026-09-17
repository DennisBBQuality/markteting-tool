<?php

namespace App\Services;

/** Only application-authored, safe-to-display SEO errors. Never provider messages. */
class ProductImageSeoException extends \RuntimeException {}
