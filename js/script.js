document
  .getElementById("awg-generate-btn")
  .addEventListener("click", function () {
    let userPrompt = document.getElementById("awg-prompt").value;
    if (!userPrompt) return;

    document.getElementById("awg-loading").style.display = "block";

    fetch(awg_ajax.ajax_url, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body:
        "action=generate_ai_response&prompt=" + encodeURIComponent(userPrompt),
    })
      .then((response) => response.json())
      .then((jsonData) => {
        document.getElementById("awg-loading").style.display = "none";

        if (jsonData.success) {
          document.getElementById("awg-html-output").innerHTML = jsonData.html;
          watchForPdf(); // Start watching for PDF updates
        } else {
          alert("Error: " + jsonData.message);
        }
      })
      .catch((error) => {
        document.getElementById("awg-loading").style.display = "none";
        console.error("Fetch Error:", error);
        alert("An unexpected error occurred.");
      });
  });

// Function to continuously check for PDF updates
function watchForPdf() {
  let attempts = 0;
  let maxAttempts = 10;

  function checkPdf() {
    fetch(awg_ajax.ajax_url + "?action=fetch_latest_pdf")
      .then((response) => response.json())
      .then((jsonData) => {
        if (jsonData.success) {
          document.getElementById("awg-pdf-frame").src = jsonData.pdf_url;
          document.getElementById("awg-pdf-container").style.display = "block";
        } else if (attempts < maxAttempts) {
          attempts++;
          setTimeout(checkPdf, 2000);
        } else {
          console.warn("No new PDF available.");
        }
      })
      .catch((error) => {
        console.error("Error fetching latest PDF:", error);
      });
  }

  checkPdf();
}
