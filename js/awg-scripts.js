document.addEventListener("DOMContentLoaded", function () {
  const viewButtons = document.querySelectorAll(".view-pdf");
  const pdfViewerContainer = document.getElementById("pdf-viewer-container");

  // Load PDF.js
  const pdfjsLib = window["pdfjs-dist/build/pdf"];

  // Configure PDF.js Worker (Required)
  pdfjsLib.GlobalWorkerOptions.workerSrc =
    "https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.worker.min.js";

  viewButtons.forEach((button) => {
    button.addEventListener("click", function () {
      const pdfUrl = this.getAttribute("data-url");

      if (!pdfUrl) {
        console.error("No PDF URL found");
        return;
      }

      // Clear previous PDF
      pdfViewerContainer.innerHTML = `<p class="text-xs text-gray-500 text-center mt-10">Loading PDF...</p>`;

      // Load PDF Document
      pdfjsLib
        .getDocument(pdfUrl)
        .promise.then((pdfDoc) => {
          console.log("PDF Loaded:", pdfDoc.numPages, "pages");

          pdfViewerContainer.innerHTML = ""; // Clear the loading message

          const containerWidth = pdfViewerContainer.clientWidth; // Get container width

          for (let pageNum = 1; pageNum <= pdfDoc.numPages; pageNum++) {
            pdfDoc.getPage(pageNum).then((page) => {
              const viewport = page.getViewport({ scale: 1 }); // Start with scale 1
              const scale = containerWidth / viewport.width; // Auto-scale based on container width
              const adjustedViewport = page.getViewport({ scale });

              // Create a <canvas> element for each page
              const canvas = document.createElement("canvas");
              canvas.width = adjustedViewport.width;
              canvas.height = adjustedViewport.height;
              canvas.classList.add("pdf-canvas");

              pdfViewerContainer.appendChild(canvas);

              const ctx = canvas.getContext("2d");
              const renderContext = {
                canvasContext: ctx,
                viewport: adjustedViewport,
              };

              page.render(renderContext);
            });
          }
        })
        .catch((error) => {
          console.error("Error loading PDF:", error);
          pdfViewerContainer.innerHTML = `<p class="text-red-500 text-xs text-center mt-10">Error loading PDF</p>`;
        });
    });
  });
});
