<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Frontend\Token\Enumerations;

enum TokenKind: string
{
    case OpenTag = 'open-tag';
    case CloseTag = 'close-tag';
    case Whitespace = 'whitespace';
    case Comment = 'comment';
    case String = 'string';
    case Heredoc = 'heredoc';
    case Identifier = 'identifier';
    case Variable = 'variable';
    case Keyword = 'keyword';
    case Number = 'number';
    case Punctuation = 'punctuation';
    case Operator = 'operator';
    case InlineHtml = 'inline-html';
    case Other = 'other';
}
