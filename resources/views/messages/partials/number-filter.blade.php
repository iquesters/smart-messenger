<form method="GET" id="numberForm">
    <input type="hidden" name="contact" id="selectedContactInput" value="{{ $selectedContact }}">
    <input type="hidden" name="number" id="selectedNumberInput" value="{{ $selectedNumber }}">
</form>

<div class="d-flex flex-wrap gap-2" role="group" aria-label="Number filter buttons">
    
    @php
        $maxVisible = 5;
        $visibleNumbers = array_slice($numbers, 0, $maxVisible);
        $dropdownNumbers = array_slice($numbers, $maxVisible);
    @endphp

    {{-- First 5 buttons --}}
    @foreach($visibleNumbers as $num)
        <button type="button"
            class="btn btn-sm d-flex align-items-center gap-2 px-2
                {{ $selectedNumber === $num['number'] ? 'btn-outline-primary' : 'btn-outline-secondary' }}"
            onclick="selectNumber('{{ $num['number'] }}')"
            data-bs-toggle="tooltip"
            data-bs-placement="bottom"
            title="{{ $num['number'] }}">

            {{-- PROVIDER ICON --}}
            <span class="fs-6">
                {!! $num['icon'] !!}
            </span>

            {{-- PROFILE NAME --}}
            <span class="fw-semibold">
                {{ $num['name'] }}
            </span>
        </button>
    @endforeach

    {{-- Dropdown for remaining numbers --}}
    @if(count($dropdownNumbers) > 0)
        <div class="btn-group" role="group">
            <button type="button" 
                class="btn btn-sm btn-outline-secondary dropdown-toggle" 
                data-bs-toggle="dropdown" 
                aria-expanded="false">
                More ({{ count($dropdownNumbers) }})
            </button>
            <ul class="dropdown-menu">
                @foreach($dropdownNumbers as $num)
                    <li>
                        <a class="dropdown-item d-flex align-items-center gap-2 {{ $selectedNumber === $num['number'] ? 'active' : '' }}" 
                           href="#"
                           onclick="event.preventDefault(); selectNumber('{{ $num['number'] }}');"
                           data-bs-toggle="tooltip"
                           title="{{ $num['number'] }}">
                            
                            {{-- PROVIDER ICON --}}
                            <span class="fs-6">
                                {!! $num['icon'] !!}
                            </span>

                            {{-- PROFILE NAME --}}
                            <span class="fw-semibold">
                                {{ $num['name'] }}
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

</div>

@push('scripts')
    <script>
        function selectNumber(number) {
            document.getElementById('selectedNumberInput').value = number;
            document.getElementById('numberForm').submit();
        }

        // Bootstrap tooltip initialization
        document.addEventListener('DOMContentLoaded', function () {
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        });
    </script>
@endpush