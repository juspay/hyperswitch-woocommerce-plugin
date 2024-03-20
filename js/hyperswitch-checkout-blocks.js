const hyperswitch_settings = window.wc.wcSettings.getSetting("hyperswitch_checkout_data", {});
const HyperswitchContent = () => {
  return window.wp.htmlEntities.decodeEntities(hyperswitch_settings.description || "");
};
const hyperswitch_label =
  window.wp.htmlEntities.decodeEntities(hyperswitch_settings.title) ||
  window.wp.i18n.__("Hyperswitch", "hyperswitch-checkout");

const Hyperswitch_Block_Gateway = {
  name: "hyperswitch_checkout",
  label: hyperswitch_label,
  content: Object(window.wp.element.createElement)(HyperswitchContent, null),
  edit: Object(window.wp.element.createElement)(HyperswitchContent, null),
  canMakePayment: () => {
    return true;
  },
  ariaLabel: label,
  supports: {
    features: settings.supports,
  },
  placeOrderButtonLabel: "Proceed to Pay",
};
window.wc.wcBlocksRegistry.registerPaymentMethod(Hyperswitch_Block_Gateway);
