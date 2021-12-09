@extends('shared.layout')

@section('looping-test')
	<p>Let's print odd numbers under 50:</p>
	<p>
		@foreach ($numbers as $number)
			@if ($loop->first)
		        This is the first iteration.
		    @endif

			@if ($loop->last)
		        This is the last iteration.
		    @endif

		    @if ($number % 2 !== 0)
				{{ $number }}
			@endif
		@endforeach
	</p>
@endsection