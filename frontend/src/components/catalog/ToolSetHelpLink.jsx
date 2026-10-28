import { ArrowRight } from 'lucide-react';
import { Link } from 'react-router-dom';
import '../../styles/tool-set-help-link.css';

export default function ToolSetHelpLink() {
  return (
    <aside className="dtb-tool-set-help" aria-label="Help choosing a tool set">
      <div className="dtb-tool-set-help__copy">
        <h2>Not sure which setup fits your crew?</h2>
        <p>Compare set contents, working range, and other buying details in our Help Center.</p>
      </div>
      <Link className="dtb-tool-set-help__link" to="/help?category=tool-sets">
        Get help choosing a set
        <ArrowRight size={16} aria-hidden="true" />
      </Link>
    </aside>
  );
}
