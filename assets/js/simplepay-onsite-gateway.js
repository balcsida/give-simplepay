/**
 * SimplePay Onsite Gateway JavaScript
 * 
 * Implements the GiveWP gateway interface for the SimplePay onsite gateway
 */
(() => {
  /**
   * SimplePay gateway API interface
   */
  const simplePayApi = {
    merchantId: "",
    isSandbox: true,
    currency: "HUF",
    orderRef: "",
    
    initialize(settings) {
      this.merchantId = settings.merchantId;
      this.isSandbox = settings.isSandbox;
      this.currency = settings.currency;
      this.orderRef = `givewp_${Date.now()}_${Math.random().toString(36).substring(2, 10)}`;
    }
  };

  /**
   * Render gateway fields
   */
  function SimplePayOnsiteGatewayFields() {
    return window.wp.element.createElement(
      "div",
      { className: "simplepay-onsite-fields" },
      [
        window.wp.element.createElement(
          "input", 
          {
            type: "hidden",
            name: "simplepay-order-ref",
            value: simplePayApi.orderRef
          }
        ),
        window.wp.element.createElement(
          "p",
          { className: "simplepay-description" },
          "You will enter your payment details after submitting the form."
        ),
        window.wp.element.createElement(
          "div",
          { className: "simplepay-logo" },
          window.wp.element.createElement(
            "img",
            {
              src: `${window.location.protocol}//${window.location.host}/wp-content/plugins/simplepay-gateway-givewp/assets/images/simplepay-logo.png`,
              alt: "SimplePay",
              style: { maxWidth: "200px", marginTop: "10px" }
            }
          )
        )
      ]
    );
  }

  /**
   * SimplePay Onsite Gateway object
   */
  const SimplePayOnsiteGateway = {
    id: "simplepay-onsite",
    initialize() {
      // Initialize the API with settings from the backend
      simplePayApi.initialize(this.settings);
    },
    
    beforeCreatePayment() {
      // Return data to be sent to the server
      return {
        "simplepay-order-ref": simplePayApi.orderRef
      };
    },
    
    Fields() {
      return window.wp.element.createElement(SimplePayOnsiteGatewayFields);
    }
  };

  // Register the gateway with GiveWP
  window.givewp.gateways.register(SimplePayOnsiteGateway);
})();
