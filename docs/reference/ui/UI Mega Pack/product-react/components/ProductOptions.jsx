import React from 'react';

export default function ProductOptions({ groups = [], selection = {}, onChange }) {
  if (!groups.length) return null;
  return <div className="product-options">
    {groups.map((group) => <fieldset className="product-options__group" key={group.id}>
      <legend>{group.label}{group.showSelected !== false && selection[group.id] && <span className="product-options__selected">: {group.options.find((option) => option.value === selection[group.id])?.label || selection[group.id]}</span>}</legend>
      <div className={`product-options__choices ${group.type === 'color' ? 'product-options__choices--colors' : ''}`}>
        {group.options.map((option) => {
          const selected = selection[group.id] === option.value;
          return <button key={option.value} type="button" className={`product-options__choice ${group.type === 'color' ? 'product-options__color' : ''} ${selected ? 'is-selected' : ''} ${option.disabled ? 'is-disabled' : ''}`}
            onClick={() => !option.disabled && onChange(group.id, option.value)} disabled={option.disabled}
            aria-pressed={selected} aria-label={group.type === 'color' ? `${option.label}${selected ? ', selected' : ''}` : undefined}>
            {group.type === 'color' ? <span className="product-options__swatch" style={{ '--swatch-color': option.color || '#777' }} aria-hidden="true" /> : option.label}
          </button>;
        })}
      </div>
    </fieldset>)}
  </div>;
}
