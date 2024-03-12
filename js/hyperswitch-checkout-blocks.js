const settings = window.wc.wcSettings.getSetting(
  "hyperswitch_checkout_data",
  {}
);
const label =
  window.wp.htmlEntities.decodeEntities(settings.title) ||
  window.wp.i18n.__("Hyperswitch", "hyperswitch-checkout");

const Block_Gateway = {
  name: "hyperswitch_checkout",
  label: label,
  content: Object(window.wp.element.createElement)(
    "div",
    { id: "hyperswitch-checkout" },
    "Sample content inside the div"
  ),
  edit: Object(window.wp.element.createElement)(
    "div",
    null,
    "Sample content inside the div"
  ),
  canMakePayment: () => {
    console.log("settings");
    console.log(settings);
    return true;
  },
  ariaLabel: label,
  supports: {
    features: settings.supports,
  },
  placeOrderButtonLabel: "Proceed to Pay",
  onSubmit: (ev) => {
    console.log("settings");
    console.log(settings);
    console.log("onsubmit");
    console.log(ev);
  },
};
window.wc.wcBlocksRegistry.registerPaymentMethod(Block_Gateway);
