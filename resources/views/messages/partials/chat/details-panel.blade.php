        {{-- DETAILS PANEL --}}
        <div id="detailsPanel"
            class="border-start bg-white d-none"
            style="width:300px; transition: all .3s ease;">

            {{-- Header --}}
            <div class="p-3 border-bottom d-flex justify-content-between align-items-center">
                <strong>Info</strong>
                <button class="btn btn-sm btn-light" id="closeDetails">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            {{-- Content --}}
            <div class="p-2 text-center">
                <div class="rounded-circle bg-primary-subtle text-primary mx-auto d-flex align-items-center justify-content-center"
                    style="width:80px;height:80px;font-size:24px;" id="detailsInitials">
                    {{ substr($selectedContact ?? '', -2) }}
                </div>

                <h6 class="mt-2" id="detailsNumber">{{ $selectedContact }}</h6>

                <!-- Accordion -->
                <div class="accordion mt-3" id="contactDetailsAccordion">

                    <!-- Contact Details -->
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="headingContactDetails">
                            <button class="accordion-button p-2"
                                type="button"
                                data-bs-toggle="collapse"
                                data-bs-target="#collapseContactDetails"
                                aria-expanded="true"
                                aria-controls="collapseContactDetails">
                                Contact Details
                            </button>
                        </h2>

                        <div id="collapseContactDetails"
                            class="accordion-collapse collapse show"
                            aria-labelledby="headingContactDetails">
                            <div class="accordion-body text-start p-2">

                                <p class="mb-1">Phone</p>
                                <p class="text-muted" id="detailsPhone">{{ $selectedContact }}</p>

                                <div class="d-flex flex-column align-items-center justify-content-center gap-2">
                                    <button class="btn text-dark btn-sm d-flex align-items-center justify-content-start gap-2 w-100 px-0">
                                        <i class="fas fa-fw fa-pen-to-square"></i> Edit Contact
                                    </button>
                                    <button class="btn text-danger btn-sm d-flex align-items-center justify-content-start gap-2 w-100 px-0">
                                        <i class="fas fa-fw fa-trash-can"></i> Delete Contact
                                    </button>
                                </div>

                            </div>
                        </div>
                    </div>

                    <!-- Integration Details -->
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="headingIntegrationDetails">
                            <button class="accordion-button p-2"
                                type="button"
                                data-bs-toggle="collapse"
                                data-bs-target="#collapseIntegrationDetails"
                                aria-expanded="true"
                                aria-controls="collapseIntegrationDetails">
                                Integration Details
                            </button>
                        </h2>

                        <div id="collapseIntegrationDetails"
                            class="accordion-collapse collapse show"
                            aria-labelledby="headingIntegrationDetails">
                            <div class="accordion-body text-start p-2">

                                <ul class="list-unstyled mb-0">
                                    <li class="d-flex align-items-center gap-2 mb-2">
                                        <i class="fas fa-cart-shopping text-primary"></i>
                                        <span>WooCommerce</span>
                                    </li>
                                    <li class="d-flex align-items-center gap-2 mb-2">
                                        <i class="fab fa-shopify text-success"></i>
                                        <span>Shopify</span>
                                    </li>
                                    <li class="d-flex align-items-center gap-2">
                                        <i class="fas fa-plug text-muted"></i>
                                        <span>Other Integrations</span>
                                    </li>
                                </ul>

                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
