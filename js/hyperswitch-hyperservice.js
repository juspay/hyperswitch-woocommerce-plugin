var hyper;
var hyperswitchWidgets;
var hyperswitchReturnUrl;
var client_data = clientdata;
var hyperswitchLoaderCustomSettings = {
    message: "",
    css: {
        padding: 0,
        margin: 0,
        width: "30%",
        top: "40%",
        left: "35%",
        textAlign: "center",
        color: "#000",
        border: "3px solid #aaa",
        backgroundColor: "#fff",
        cursor: "wait"
    },
    themedCSS: {
        width: "30%",
        top: "40%",
        left: "35%"
    },
    overlayCSS: {
        backgroundColor: "#fff",
        opacity: .6,
        cursor: "wait"
    },
    growlCSS: {
        width: "350px",
        top: "10px",
        left: "",
        right: "10px",
        border: "none",
        padding: "5px",
        opacity: .6,
        cursor: "default",
        color: "#000",
        backgroundColor: "#fff",
        "-webkit-border-radius": "10px",
        "-moz-border-radius": "10px",
        "border-radius": "10px"
    }
};
var hyperswitchUnifiedCheckoutOptions;
var hyperswitchUnifiedCheckout;
var hyperswitchUpdatePaymentIntentLock = false;
var hyperswitchLastUpdatedFormData = '';
var hyperswitchPublishableKey;
var endpoint;

function renderHyperswitchSDK(client_secret, return_url) {
    var { publishable_key, appearance_obj, layout, enable_saved_payment_methods, show_card_from_by_default, endpoint, plugin_url, plugin_version } = client_data;
    console.log(client_data);
    hyper = Hyper(publishable_key);
    hyperswitchPublishableKey = publishable_key;
    clientSecret = client_secret;
    hyperswitchReturnUrl = return_url;
    var appearance;
    try {
        appearance = JSON.parse(appearance_obj);
    } catch (_e) {
        appearance = {};
    }

    async function updateIconFromPaymentMethods() {
        try {
            const response = await fetch(endpoint + "/account/payment_methods?client_secret=" + clientSecret, {
                "headers": {
                    "accept": "*/*",
                    "api-key": hyperswitchPublishableKey,
                    "content-type": "application/json",
                },
                "body": null,
                "method": "GET",
                "mode": "cors",
                "credentials": "include"
            });
            const data = await response.json();
            var pmts = []
            if (data.payment_methods) {
                data.payment_methods.forEach(paymentMethod => {
                    paymentMethod.payment_method_types.forEach(pmt => {
                        pmts.push(pmt.payment_method_type);
                    })
                })
            }
            var pmt_set = new Set(pmts);
            pmt_set = Array.from(pmt_set);
            var i = 0;
            setInterval(function () {
                jQuery('.wc_payment_method.payment_method_hyperswitch_payment label img').css("opacity", 0).attr("src", plugin_url + pmt_set[i] + '.svg?version=' + plugin_version).attr("alt", pmt_set[i]).animate({ opacity: 1 }, "slow");
                i++;
                i %= pmt_set.length;
            }, 3000);
        }
        catch (error) {
            console.log(error)
        }
    }

    updateIconFromPaymentMethods();

    if (appearance.variables) {
        variables = appearance.variables;
    } else {
        variables = {};
    }
    if (!variables.fontFamily) {
        var fontFamily = jQuery('#payment, #payment-form, body').css("font-family");
        variables.fontFamily = fontFamily;
    }
    if (!variables.fontSizeBase) {
        var fontSizeBase = jQuery('#payment, #payment-form, body').css("font-size");
        variables.fontSizeBase = fontSizeBase;
    }
    if (!variables.colorPrimary) {
        var colorPrimary = jQuery('#payment, #payment-form, body').css("color");
        variables.colorPrimary = colorPrimary;
    }
    if (!variables.colorText) {
        var colorText = jQuery('#payment, #payment-form, body').css("color");
        variables.colorText = colorText;
    }
    if (!variables.colorTextSecondary) {
        var colorTextSecondary = jQuery('#payment, #payment-form, body').css("color");
        variables.colorTextSecondary = colorStringToHex(colorTextSecondary) + "B3";
    }
    if (!variables.colorPrimaryText) {
        var colorPrimaryText = jQuery('#payment, #payment-form, body').css("color");
        variables.colorPrimaryText = colorPrimaryText;
    }
    if (!variables.colorTextPlaceholder) {
        var colorTextPlaceholder = jQuery('#payment, #payment-form, body').css("color");
        variables.colorTextPlaceholder = colorStringToHex(colorTextPlaceholder) + "50";
    }
    if (!variables.borderColor) {
        var borderColor = jQuery('#payment, #payment-form, body').css("color");
        variables.borderColor = colorStringToHex(borderColor) + "50";
    }
    if (!variables.colorBackground) {
        var colorBackground = jQuery('body').css("background-color");
        variables.colorBackground = colorBackground;
    }

    appearance.variables = variables;

    hyperswitchWidgets = hyper.widgets({ appearance, clientSecret });

    if (checkWcHexIsLight(colorStringToHex(variables.colorBackground))) {
        theme = "dark";
    } else {
        theme = "light";
    }

    style = {
        theme: theme
    }

    var layout1 = {
        type: layout === "spaced" ? "accordion" : layout,
        defaultCollapsed: false,
        radios: true,
        spacedAccordionItems: layout === "spaced",
    }

    var disableSaveCards = !enable_saved_payment_methods;
    var showCardFormByDefault = !!show_card_from_by_default;

    hyperswitchUnifiedCheckoutOptions = {
        layout: layout1,
        wallets: {
            walletReturnUrl: hyperswitchReturnUrl,
            style: style
        },
        sdkHandleConfirmPayment: false,
        disableSaveCards: disableSaveCards,
        branding: "never",
        showCardFormByDefault: showCardFormByDefault
    };
    hyperswitchUnifiedCheckout = hyperswitchWidgets.create("payment", hyperswitchUnifiedCheckoutOptions);
    hyperswitchUnifiedCheckout.mount("#unified-checkout");
}

function handleHyperswitchAjax() {
    jQuery(".woocommerce-error").remove();
    jQuery(".payment_method_hyperswitch_payment").block(hyperswitchLoaderCustomSettings);
    clientSecret = jQuery('#payment-form').data('client-secret');
    request = jQuery.ajax({
        type: "post",
        url: "/?wc-ajax=checkout",
        data: jQuery("form.checkout").serialize(),
        success: function (msg) {
            if (msg.result == "success") {
                var order_id = msg.order_id;
                if (!order_id) {
                    var redirect_uri = msg.redirect;
                    var regex = /\/(\d+)\//;
                    if (redirect_uri) {
                        var match = redirect_uri.match(regex);

                        if (match && match[1]) {
                            order_id = match[1];
                        }
                    }
                }
                var payment_intent_data = {
                    action: "create_payment_intent_from_order",
                    order_id: order_id
                }
                if (clientSecret) {
                    payment_intent_data.client_secret = clientSecret;
                }
                if (order_id) {
                    request = jQuery.ajax({
                        type: "post",
                        url: "/wp-admin/admin-ajax.php",
                        data: payment_intent_data,
                        success: function (_msg2) {
                            hyperswitchPaymentHandleSubmit();
                        }
                    });
                }
            } else {
                jQuery(".payment_method_hyperswitch_payment").unblock();
                jQuery(".woocommerce").prepend(msg.messages);
                jQuery([document.documentElement, document.body]).animate({
                    scrollTop: jQuery(".woocommerce").offset().top - 20
                }, 250);
            }
        }
    });
}

document.addEventListener("DOMContentLoaded", () => {
    jQuery('form.checkout, form.checkout_coupon').on("change", function (event) {
        paymentMethod = new URLSearchParams(jQuery("form.checkout").serialize()).get('payment_method');
        clientSecret = jQuery('#payment-form').data('client-secret');
        // Ignore when other payment method selected, default behaviour is not affected
        if (paymentMethod === "hyperswitch_payment" || clientSecret == null) {
            let inputChangeId = event.target.id;
            if (!hyperswitchUpdatePaymentIntentLock) {
                updatePaymentIntent(inputChangeId);
            }
        }
    });
});

async function hyperswitchPaymentHandleSubmit() {
    const { error } = await hyper.confirmPayment({
        widgets: hyperswitchWidgets,
        confirmParams: {
            return_url: hyperswitchReturnUrl
        },
        redirect: "if_required"
    });
    if (error) {
        if (error.type) {
            if (error.type == "validation_error") {
                jQuery([document.documentElement, document.body]).animate({
                    scrollTop: jQuery(".payment_box.payment_method_hyperswitch_payment").offset().top
                }, 500);
            } else {
                location.href = hyperswitchReturnUrl;
            }
        }
        else {
            location.href = hyperswitchReturnUrl;
        }
        jQuery(".payment_method_hyperswitch_payment").unblock();
    } else {
        location.href = hyperswitchReturnUrl;
    }
}

function updatePaymentIntent(inputChangeId) {
    if (!hyperswitchUpdatePaymentIntentLock) {
        hyperswitchUpdatePaymentIntentLock = true;
        jQuery(".payment_method_hyperswitch_payment").block(hyperswitchLoaderCustomSettings);
        var formData = jQuery("form.checkout").serialize();
        var forceIntentUpdate = (inputChangeId.indexOf("country") !== -1)
        clientSecret = jQuery('#payment-form').data('client-secret');
        var payment_intent_data = {
            action: "create_payment_intent_from_order"
        }
        if (clientSecret) {
            payment_intent_data.client_secret = clientSecret;
        }
        if (clientSecret == null || forceIntentUpdate) {
            request = jQuery.ajax({
                type: "post",
                url: "/wp-admin/admin-ajax.php",
                data: payment_intent_data,
                success: function (msg2) {
                    if (clientSecret == null || (msg2.payment_sheet && forceIntentUpdate)) {
                        jQuery(".payment_box.payment_method_hyperswitch_payment").html(msg2.payment_sheet).addClass("payment_sheet");
                    }
                    jQuery(".payment_method_hyperswitch_payment").unblock(hyperswitchLoaderCustomSettings);
                    hyperswitchUpdatePaymentIntentLock = false;
                    jQuery(".woocommerce-error").remove();
                    hyperswitchLastUpdatedFormData = formData;
                },
                error: function (_error) {
                    jQuery(".payment_method_hyperswitch_payment").unblock(hyperswitchLoaderCustomSettings);
                    hyperswitchUpdatePaymentIntentLock = false;
                }
            });
        } else {
            jQuery(".woocommerce-error").remove();
            jQuery(".payment_method_hyperswitch_payment").unblock(hyperswitchLoaderCustomSettings);
            hyperswitchUpdatePaymentIntentLock = false;
        }
    }
}

function checkWcHexIsLight(color) {
    const hex = color.replace('#', '');
    const c_r = parseInt(hex.substr(0, 2), 16);
    const c_g = parseInt(hex.substr(2, 2), 16);
    const c_b = parseInt(hex.substr(4, 2), 16);
    const brightness = ((c_r * 299) + (c_g * 587) + (c_b * 114)) / 1000;
    return brightness > 155;
}

function componentToHex(c) {
    const hex = c.toString(16);
    return hex.length === 1 ? '0' + hex : hex;
}

function rgbToHex(r, g, b) {
    return '#' + componentToHex(r) + componentToHex(g) + componentToHex(b);
}

function rgbaToHex(r, g, b, a) {
    return '#' + componentToHex(r) + componentToHex(g) + componentToHex(b) + componentToHex(Math.round(a * 255));
}

function hexToHex(hex) {
    hex = hex.replace('#', '').toUpperCase();
    if (hex.length === 3) {
        hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
    }

    return '#' + hex;
}

function colorStringToHex(colorString) {
    if (colorString.startsWith('rgb(')) {
        const values = colorString.substring(4, colorString.length - 1).split(',').map(val => parseInt(val));
        return rgbToHex(values[0], values[1], values[2]);
    } else if (colorString.startsWith('rgba(')) {
        const values = colorString.substring(5, colorString.length - 1).split(',').map(val => parseFloat(val));
        return rgbaToHex(values[0], values[1], values[2], values[3]);
    } else if (colorString.startsWith('#')) {
        return hexToHex(colorString);
    } else {
        throw new Error('Invalid color string');
    }
}

function checkMultiplePaymentMethods() {
    if (jQuery(".wc_payment_methods.payment_methods.methods .wc_payment_method").length > 1) {
        if (jQuery('label[for="payment_method_hyperswitch_payment"]').length) {
            jQuery('label[for="payment_method_hyperswitch_payment"]').css({ display: "inline" });
        }
    }
}
function stopCheckMultiplePaymentMethods() {
    clearInterval(checkMultiplePaymentMethodsInterval)
}
const checkMultiplePaymentMethodsInterval = setInterval(checkMultiplePaymentMethods, 500);