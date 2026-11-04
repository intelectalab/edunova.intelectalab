import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import App from './App';

describe('App', () => {
  it('muestra la marca y el título de la web de gestión', () => {
    render(<App />);
    expect(screen.getByText('Edunova')).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Web de gestión' })).toBeInTheDocument();
  });
});
