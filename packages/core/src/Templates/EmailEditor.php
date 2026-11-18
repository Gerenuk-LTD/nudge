<?php

namespace Gerenuk\Nudge\Templates;

/**
 * The editors an email's content can be made with. The front end has a matching registry of editor components.
 */
final class EmailEditor
{
    /** The drag-and-drop builder, which keeps its document in `editor_state`. */
    public const BLOCKS = 'blocks';

    /** Hand-written HTML, with no editor state. */
    public const HTML = 'html';

    public const KEYS = [self::BLOCKS, self::HTML];
}
