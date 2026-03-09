const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { createElement } = window.wp.element;
const alliancePaySettings = window.albHppBlocksSettings;

registerPaymentMethod({
    name: alliancePaySettings.id,
    label: alliancePaySettings.name,
    content: createElement('p', {}, alliancePaySettings.description),
    edit: createElement(alliancePaySettings.id),
    canMakePayment: () => true,
    ariaLabel: alliancePaySettings.name,
    supports: {
        features: alliancePaySettings.features,
    },
});
