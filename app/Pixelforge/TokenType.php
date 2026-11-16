<?php

namespace App\Pixelforge;

/**
 * The kinds of token Pixelforge bills for. The values are the keys used in
 * both `usage` and `rates` on the generation object.
 */
enum TokenType: string
{
    case InputText = 'input_text_tokens';
    case InputImage = 'input_image_tokens';
    case Output = 'output_tokens';
    case Reasoning = 'reasoning_tokens';
}
