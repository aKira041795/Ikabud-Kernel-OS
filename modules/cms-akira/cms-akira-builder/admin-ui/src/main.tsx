import { createRoot } from 'react-dom/client';
import App from './app';
import './styles.css';

const container = document.getElementById('cms-akira-builder-root');
if (!container) {
  throw new Error('CMS Akira Builder root container (#cms-akira-builder-root) not found.');
}
createRoot(container).render(<App />);
