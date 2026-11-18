import type { Block, ContentBlock } from './model';

/**
 * Where blocks live: `root` is the email itself, and `<columnsId>:<index>` is one column of a columns block.
 */
export const ROOT = 'root';

export function columnContainer(columnsId: string, index: number): string {
    return `${columnsId}:${index}`;
}

function parseContainer(container: string): [string, number] | null {
    const separator = container.lastIndexOf(':');

    return separator === -1
        ? null
        : [
              container.slice(0, separator),
              Number(container.slice(separator + 1)),
          ];
}

export function getContainer(blocks: Block[], container: string): Block[] {
    if (container === ROOT) {
        return blocks;
    }

    const [columnsId, index] = parseContainer(container) ?? ['', 0];
    const columns = blocks.find((block) => block.id === columnsId);

    return columns?.type === 'columns' ? (columns.columns[index] ?? []) : [];
}

export function setContainer(
    blocks: Block[],
    container: string,
    children: Block[],
): Block[] {
    if (container === ROOT) {
        return children;
    }

    const [columnsId, index] = parseContainer(container) ?? ['', 0];

    return blocks.map((block) =>
        block.id === columnsId && block.type === 'columns'
            ? {
                  ...block,
                  columns: block.columns.map((column, position) =>
                      position === index
                          ? (children as ContentBlock[])
                          : column,
                  ),
              }
            : block,
    );
}

/** Which container holds a block, and where in it. */
export function locate(
    blocks: Block[],
    id: string,
): { container: string; index: number } | null {
    const index = blocks.findIndex((block) => block.id === id);

    if (index !== -1) {
        return { container: ROOT, index };
    }

    for (const block of blocks) {
        if (block.type !== 'columns') {
            continue;
        }

        for (const [position, column] of block.columns.entries()) {
            const inner = column.findIndex((child) => child.id === id);

            if (inner !== -1) {
                return {
                    container: columnContainer(block.id, position),
                    index: inner,
                };
            }
        }
    }

    return null;
}

export function findBlock(blocks: Block[], id: string | null): Block | null {
    if (id === null) {
        return null;
    }

    const location = locate(blocks, id);

    return location
        ? getContainer(blocks, location.container)[location.index]
        : null;
}

export function updateBlock(
    blocks: Block[],
    id: string,
    change: (block: Block) => Block,
): Block[] {
    const location = locate(blocks, id);

    if (!location) {
        return blocks;
    }

    const children = [...getContainer(blocks, location.container)];
    children[location.index] = change(children[location.index]);

    return setContainer(blocks, location.container, children);
}

export function removeBlock(blocks: Block[], id: string): Block[] {
    const location = locate(blocks, id);

    if (!location) {
        return blocks;
    }

    return setContainer(
        blocks,
        location.container,
        getContainer(blocks, location.container).filter(
            (block) => block.id !== id,
        ),
    );
}

/**
 * The blocks with one inserted into a container at an index. A columns block cannot go inside a column, so it lands
 * in the email just after the columns it was dropped into instead.
 */
export function insertBlock(
    blocks: Block[],
    block: Block,
    container: string,
    index: number,
): Block[] {
    if (block.type === 'columns' && container !== ROOT) {
        const [columnsId] = parseContainer(container) ?? [''];
        const after = blocks.findIndex(
            (candidate) => candidate.id === columnsId,
        );

        return insertBlock(blocks, block, ROOT, after + 1);
    }

    const children = [...getContainer(blocks, container)];
    children.splice(Math.max(0, Math.min(index, children.length)), 0, block);

    return setContainer(blocks, container, children);
}

/** The blocks with one moved to a new container and index, counted as if it were not in its old place. */
export function moveBlock(
    blocks: Block[],
    id: string,
    container: string,
    index: number,
): Block[] {
    const block = findBlock(blocks, id);

    if (!block || (block.type === 'columns' && container !== ROOT)) {
        return blocks;
    }

    return insertBlock(removeBlock(blocks, id), block, container, index);
}
