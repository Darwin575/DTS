<!-- Review Modal -->
<div class="modal fade" id="reviewModal" data-backdrop="static" data-keyboard="false" tabindex="-1" aria-labelledby="reviewModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <form id="finalReviewForm" class="d-flex flex-column h-100">
        <input type="hidden" name="draft_id" value="123">

        <!-- Modal Body -->
        <div class="modal-body overflow-auto">
          <div id="review-fields" class="container-fluid p-3">
            <div class="row g-3">
              <!-- First (wider) column -->
              <div class="col-12 col-md-8">
                <!-- Your left‐side fields go here -->
                <label for="fieldA">Field A</label>
                <input id="fieldA" class="form-control mb-3" />

                <label for="fieldB">Field B</label>
                <textarea id="fieldB" class="form-control"></textarea>
              </div>

              <!-- Second (narrower) column -->
              <div class="col-12 col-md-4">
                <!-- Your right‐side fields go here -->
                <label for="fieldC">Field C</label>
                <input id="fieldC" class="form-control mb-3" />

                <label for="fieldD">Field D</label>
                <input id="fieldD" class="form-control" />
              </div>
            </div>
            <hr class="my-2">
          </div>
        </div>

        <!-- Modal Footer -->
        <div class="modal-footer bg-light">
          <div class="container-fluid px-0">
            <div class="row g-2">
              <div class="col-12 order-1">
                <button type="button" class="btn btn-outline-secondary w-100" id="reviewEditBtn">
                  <i class="bi bi-pencil me-2"></i>Edit
                </button>
              </div>

              <div class="col-12 order-2">
                <button type="button" class="btn btn-success w-100" id="createRouteSheetBtn">
                  <i class="bi bi-file-text me-2"></i>Route Sheet (with Codes) → Route
                </button>
              </div>
            </div>
          </div>
        </div>
      </form>


      <?php include __DIR__ . '/route_sheet_overlay.php'; ?>
    </div>
  </div>

</div>





<!-- Required Libraries -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script>
  // Attach handler for the Route Sheet (with Codes) button
  $('#createRouteSheetBtn').on('click', async function() {
    // Try to get the document ID from the input field first.
    let documentId = $('#finalReviewForm input[name="draft_id"]').val();

    // If it's not found, look for it in the URL query parameters.
    if (!documentId) {
      const urlParams = new URLSearchParams(window.location.search);
      documentId = urlParams.get('draft_id');
    }

    // If still not found, alert the user and exit the function.
    if (!documentId) {
      alert("Document ID is missing. Cannot generate route sheet.");
      return;
    }


    // 1. Try to generate codes if missing (will not overwrite existing codes)
    try {
      const resp = await fetch('/DTS/server-logic/user-operations/generate-codes.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          document_id: documentId
        })
      });
      const result = await resp.json();
      if (!result.success) {
        alert('Failed to generate codes: ' + result.message);
        return;
      }

      // 2. Proceed to open the PDF in a new tab
    } catch (e) {
      alert('There was an error talking to the server: ' + e.message);
    }
  });
  $('#reviewEditBtn').on('click', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const rejectedId = urlParams.get('rejected_doc_id');

    if (rejectedId) {
      // navigate back into “revise‐after‐reject” mode
      window.location.href = `document_management.php?rejected_doc_id=${rejectedId}`;
      return; // stop further code in this handler
    }

    let draftId = $('#finalReviewForm input[name="draft_id"]').val() ||
      urlParams.get('draft_id');

    if (draftId) {
      window.location.href = `document_management.php?draft_id=${draftId}`;
    } else {
      alert("Draft ID is missing.");
    }
  });



  $('#createRouteSheetBtn').on('click', async function() {
    // Try to get the document ID from the input field first.
    let documentId = $('#finalReviewForm input[name="draft_id"]').val();

    // If it's not found, look for it in the URL query parameters.
    if (!documentId) {
      const urlParams = new URLSearchParams(window.location.search);
      documentId = urlParams.get('draft_id');
    }

    // If still not found, alert the user and exit the function.
    if (!documentId) {
      alert("Document ID is missing. Cannot generate route sheet.");
      return;
    }

    // Proceed with your logic using the valid documentId.





    // Use the modular overlay's open function
    if (typeof openRouteSheetOverlay === 'function') {
      openRouteSheetOverlay(documentId);
    } else {
      alert('Route overlay module not found.');
    }
  });
  $(function() {
    $('#reviewModal').on('hidden.bs.modal', function() {
      // Remove `draft_id` from the URL without reloading the page
      const url = new URL(window.location.href);
      url.searchParams.delete('draft_id');
      window.history.replaceState({}, document.title, url.pathname + url.search);
    });

    // Reset route sheet overlay on modal close
    $('#reviewModal').on('show.bs.modal hidden.bs.modal', () => {
      if (typeof hideRouteSheetOverlay === 'function') {
        hideRouteSheetOverlay();
      }
    });
  });
</script>
<style>
  @media (min-width: 992px) {
    #reviewModal .modal-dialog {
      max-width: 90%;
      min-width: 1000px;
    }

    #reviewModal .modal-content {
      min-height: 85vh;
    }

    #reviewModal .modal-body {
      padding: 2rem 3rem;
    }

    #reviewModal .modal-footer {
      padding: 2rem 3rem;
    }
  }

  /* Scrollbar styling */
  .modal-body::-webkit-scrollbar {
    width: 8px;
  }

  .modal-body::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 4px;
  }

  .modal-body::-webkit-scrollbar-thumb {
    background: #888;
    border-radius: 4px;
  }

  .modal-body::-webkit-scrollbar-thumb:hover {
    background: #555;
  }

  @media (min-width: 768px) {

    /* Turn the review‐fields wrapper into a flex row */
    #reviewModal #review-fields {
      display: flex;
      gap: 1rem;
      /* space between columns */
      width: 100%;
      /* fill the modal body */
    }

    /* Ensure children can shrink/grow */
    #reviewModal #review-fields>* {
      flex-basis: 0;
      /* allows flex-grow to work */
    }

    /* First child twice as wide as the second */
    #reviewModal #review-fields>*:first-child {
      flex-grow: 2;
    }

    #reviewModal #review-fields>*:nth-child(2) {
      flex-grow: 1;
    }
  }
</style>