import { arrayMove } from '@dnd-kit/sortable';
import { describe, expect, it } from 'vitest';
import type { Block } from './model';
import { createBlock } from './model';
import {
    columnContainer,
    insertBlock,
    moveBlock,
    removeBlock,
    ROOT,
} from './tree';

function spacer(id: string): Block {
    return { id, type: 'spacer', height: 10 };
}

function ids(blocks: Block[]): string[] {
    return blocks.map((block) => block.id);
}

describe('tree', () => {
    const blocks = [spacer('a'), spacer('b'), spacer('c')];

    it('inserts a block at an index', () => {
        expect(ids(insertBlock(blocks, spacer('x'), ROOT, 1))).toEqual([
            'a',
            'x',
            'b',
            'c',
        ]);
    });

    it('moves a block from index 0 to 2 like arrayMove', () => {
        const moved = moveBlock(blocks, 'a', ROOT, 2);

        expect(ids(moved)).toEqual(ids(arrayMove(blocks, 0, 2)));
    });

    it('removes a block', () => {
        expect(ids(removeBlock(blocks, 'b'))).toEqual(['a', 'c']);
    });

    it('does not put a columns block inside a column', () => {
        const columns = createBlock('columns');
        const nested = createBlock('columns');
        const result = insertBlock(
            [columns],
            nested,
            columnContainer(columns.id, 0),
            0,
        );

        expect(ids(result)).toEqual([columns.id, nested.id]);
    });

    it('clamps an out-of-range index', () => {
        expect(ids(insertBlock(blocks, spacer('x'), ROOT, 99))).toEqual([
            'a',
            'b',
            'c',
            'x',
        ]);
        expect(ids(insertBlock(blocks, spacer('y'), ROOT, -5))).toEqual([
            'y',
            'a',
            'b',
            'c',
        ]);
    });
});
