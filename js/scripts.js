jQuery(document).ready(function ($) {
  $("#checkout-btn").on("click", function () {
    $.ajax({
      url: awg_ajax.ajax_url, // Use the localized AJAX URL
      type: "POST",
      data: {
        action: "awg_checkout",
      },
      success: function (response) {
        if (response.success && response.data.redirect) {
          window.location.href = response.data.redirect; // Redirect to checkout
        } else {
          alert(response.data.message || "An error occurred.");
        }
      },
      error: function () {
        alert("Something went wrong. Please try again.");
      },
    });
  });
});
