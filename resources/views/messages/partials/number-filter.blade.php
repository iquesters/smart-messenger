<form method="GET" class="row g-2 mb-2" id="numberForm">
    <input type="hidden" name="contact" id="hiddenContact" value="{{ $selectedContact }}">

    <div class="col-md-3">
        <select name="number" class="form-select" onchange="this.form.submit()">
            <option value="">-- Select Your Number --</option>

            @foreach($numbers as $num)
                <option value="{{ $num['number'] }}"
                    {{ $selectedNumber == $num['number'] ? 'selected' : '' }}>
                    {{ $num['number'] }}
                </option>
            @endforeach
        </select>
    </div>
</form>

{{-- NO NUMBER SELECTED --}}
@if(!$selectedNumber)
    <div class="alert alert-info">Please select your number to view messages.</div>
@endif