/**
 * SimplePay Offsite Gateway JavaScript
 * 
 * Implements the GiveWP gateway interface for the SimplePay offsite gateway
 */
(() => {
  let settings = {};

  /**
   * Render gateway fields
   */
  function SimplePayOffsiteGatewayFields() {
    return window.wp.element.createElement(
      "div",
      { className: "simplepay-offsite-fields" },
      [
        window.wp.element.createElement(
          "p",
          { className: "simplepay-description" },
          settings.message
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
   * SimplePay Offsite Gateway object
   */
  const SimplePayOffsiteGateway = {
    id: "simplepay-offsite",
    initialize() {
      // Store settings from the backend
      settings = this.settings;
    },
    
    Fields() {
      return window.wp.element.createElement(SimplePayOffsiteGatewayFields);
    }
  };

  // Register the gateway with GiveWP
  window.givewp.gateways.register(SimplePayOffsiteGateway);
})();
