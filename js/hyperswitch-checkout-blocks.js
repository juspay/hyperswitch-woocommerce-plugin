const settings = window.wc.wcSettings.getSetting(
  "hyperswitch_checkout_data",
  {}
);
const Content = () => {
  return window.wp.htmlEntities.decodeEntities(settings.description || "");
};
const label =
  window.wp.htmlEntities.decodeEntities(settings.title) ||
  window.wp.i18n.__("Hyperswitch", "hyperswitch-checkout");

const Block_Gateway = {
  name: "hyperswitch_checkout",
  label: label,
  content: Object(window.wp.element.createElement)(Content, null),
  edit: Object(window.wp.element.createElement)(Content, null),
  canMakePayment: () => {
    return true;
  },
  ariaLabel: label,
  supports: {
    features: settings.supports,
  },
  placeOrderButtonLabel: "Proceed to Pay",
};
window.wc.wcBlocksRegistry.registerPaymentMethod(Block_Gateway);
