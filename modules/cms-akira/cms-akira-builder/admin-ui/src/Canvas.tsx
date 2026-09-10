import type { Block, BlockDefinition } from './types';

function propsSummary(props: Record<string, unknown>): string {
  const findString = (value: unknown): string => {
    if (typeof value === 'string' && value.trim() !== '') return value.trim();
    if (Array.isArray(value)) {
      for (const item of value) {
        const found = findString(item);
        if (found) return found;
      }
    } else if (value !== null && typeof value === 'object') {
      for (const item of Object.values(value as Record<string, unknown>)) {
        const found = findString(item);
        if (found) return found;
      }
    }
    return '';
  };
  const summary = findString(props);
  return summary.length > 90 ? `${summary.slice(0, 87)}…` : summary || 'No text properties';
}

export function Canvas({ blocks, catalogue, selectedIndex, onSelect, onMove, onDuplicate, onDelete }: {
  blocks: Block[];
  catalogue: BlockDefinition[];
  selectedIndex: number | null;
  onSelect: (index: number) => void;
  onMove: (index: number, direction: -1 | 1) => void;
  onDuplicate: (index: number) => void;
  onDelete: (index: number) => void;
}) {
  return (
    <div className="ab-canvas" aria-label="Composition block canvas">
      {blocks.length === 0 ? <p className="ab-muted">No blocks yet. Add a theme block below.</p> : blocks.map((block, index) => {
        const definition = catalogue.find((item) => item.id === block.block);
        const selected = selectedIndex === index;
        return (
          <article className={`ab-canvas-card${selected ? ' ab-canvas-card-selected' : ''}`} key={`${index}-${block.block}`} aria-selected={selected} aria-current={selected ? 'true' : undefined}>
            <div className="ab-canvas-heading">
              <strong>{index + 1}. {definition?.label ?? block.block}</strong>
              <span className="ab-muted">{definition?.category ?? 'unknown block'}</span>
            </div>
            {!definition && <p className="ab-canvas-warning">Unknown block — validation will reject this id.</p>}
            <p className="ab-canvas-summary">{propsSummary(block.props)}</p>
            <div className="ab-btnrow">
              <button className="ab-btn" type="button" onClick={() => onSelect(index)}>{selected ? 'Selected' : 'Select'}</button>
              <button className="ab-btn" type="button" disabled={index === 0} onClick={() => onMove(index, -1)}>Move up</button>
              <button className="ab-btn" type="button" disabled={index === blocks.length - 1} onClick={() => onMove(index, 1)}>Move down</button>
              <button className="ab-btn" type="button" onClick={() => onDuplicate(index)}>Duplicate</button>
              <button className="ab-btn ab-btn-danger" type="button" onClick={() => onDelete(index)}>Delete</button>
            </div>
          </article>
        );
      })}
    </div>
  );
}
