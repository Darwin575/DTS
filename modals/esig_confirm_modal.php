<?php
// /modals/esig_confirm_modal.php
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css" />
<script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
<style>
  .esig-modal-bg {
    position: fixed;
    z-index: 2003;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(30, 38, 55, 0.21);
    display: none;
    align-items: center;
    justify-content: center;
  }

  .esig-modal-bg.active {
    display: flex;
  }

  .esig-modal {
    background: #fff;
    border-radius: 13px;
    box-shadow: 0 9px 40px rgba(30, 38, 55, 0.17);
    max-width: 580px;
    width: 97vw;
    padding: 0 0 20px;
    display: flex;
    flex-direction: column;
    align-items: stretch;
    position: relative;
  }

  .esig-modal-header {
    padding: 22px 28px 15px 28px;
    font-size: 1.15rem;
    font-weight: 600;
    color: #2e3955;
    text-align: center;
    border-bottom: 1px solid #f7f7f7;
  }

  .esig-modal-body {
    padding: 18px 17px 9px 17px;
    background: #fafbfc;
    display: flex;
    flex-direction: column;
    align-items: center;
  }

  .esig-modal-footer {
    display: flex;
    justify-content: center;
    gap: 12px;
    padding: 16px 19px 0 19px;
    border-top: 1px solid #f2f2f2;
  }

  .esig-modal-close {
    position: absolute;
    top: 12px;
    right: 15px;
    color: #aaa;
    background: none;
    border: none;
    font-size: 1.2em;
    cursor: pointer;
  }

  .esig-iframe-wrapper {
    width: 100%;
    max-width: 99%;
    min-height: 300px;
    max-height: 50vh;
    margin-bottom: 10px;
  }

  .esig-iframe-wrapper iframe {
    width: 100%;
    height: 42vh;
    min-height: 300px;
    border-radius: 8px;
    border: 1px solid #e7e7f2;
    background: #fff;
  }

  @media (max-width: 610px) {
    .esig-modal {
      max-width: 99vw;
    }

    .esig-modal-header,
    .esig-modal-body,
    .esig-modal-footer {
      padding-left: 3vw;
      padding-right: 3vw;
    }
  }
</style>

<div class="esig-modal-bg" id="esigConfirmModalBg">
  <div class="esig-modal" role="dialog" aria-modal="true" aria-labelledby="esigConfirmHeader">
    <button class="esig-modal-close" aria-label="Close" id="esigConfirmCloseBtn">&times;</button>
    <div class="esig-modal-header" id="esigConfirmHeader">
      <i class="bi bi-check-circle-fill text-success me-2"></i>
      Confirm E-signature & Routing
    </div>
    <div class="esig-modal-body">
      <div id="esigConfirmMsg" class="mb-2 text-center" style="font-size:1.09em;">
        Are you sure you want to append your e-signature and route this document?
      </div>
      <div class="esig-iframe-wrapper mb-2">
        <iframe id="esigRouteSheetIframe" allow="clipboard-write"></iframe>
      </div>
      <div class="d-flex justify-content-center align-items-center" id="esigActionSpinner" style="display:none">
        <span class="spinner-border text-success" role="status"></span>
        <span class="ms-2">Processing...</span>
      </div>
    </div>
    <div class="esig-modal-footer">
      <button class="btn btn-outline-secondary" type="button" id="esigConfirmCancelBtn">
        <i class="bi bi-x-circle"></i> Cancel
      </button>
      <button class="btn btn-success" type="button" id="esigConfirmProceedBtn">
        <i class="bi bi-check-circle"></i> Yes, Proceed
      </button>
    </div>
  </div>
</div>