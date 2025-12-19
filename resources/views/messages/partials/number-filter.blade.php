<form method="GET" id="numberForm">
    <input type="hidden" name="contact" value="{{ $selectedContact }}">
    <input type="hidden" name="number" id="selectedNumberInput">
</form>

<div class="btn-group mt-2" role="group" aria-label="Number selector">

    @foreach($numbers as $index => $num)
        @if($index < 5)
            <button type="button"
                class="btn {{ $selectedNumber == $num['number'] ? 'btn-primary' : 'btn-outline-primary' }}"
                onclick="selectNumber('{{ $num['number'] }}')">
                {{ $num['number'] }}
            </button>
        @endif
    @endforeach

    @if(count($numbers) > 5)
        <div class="btn-group" role="group">
            <button type="button"
                class="btn btn-outline-primary dropdown-toggle"
                data-bs-toggle="dropdown"
                aria-expanded="false">
                More
            </button>

            <ul class="dropdown-menu">
                @foreach($numbers as $index => $num)
                    @if($index >= 5)
                        <li>
                            <a class="dropdown-item"
                               href="javascript:void(0)"
                               onclick="selectNumber('{{ $num['number'] }}')">
                                {{ $num['number'] }}
                            </a>
                        </li>
                    @endif
                @endforeach
            </ul>
        </div>
    @endif
</div>

{{-- NO NUMBER SELECTED --}}
@if(!$selectedNumber)
    <div class="alert alert-info mt-3">
        Please select your number to view messages.
    </div>
@endif
