(() => {
  const el = window.wp.element.createElement;
  const gateway = {
    id: 'simplepay',
    Fields() {
      const s = gateway.settings || {};
      return el('div', { className: 'simplepay-fields' },
        el('p', null, s.message),
        el('p', { className: 'simplepay-declaration' }, s.declaration, ' ',
          el('a', { href: s.privacyUrl, target: '_blank', rel: 'noopener noreferrer' }, s.privacyLinkText)));
    },
  };
  window.givewp.gateways.register(gateway);
})();
