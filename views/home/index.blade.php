@extends('shared.layout')

@section('looping-test')
	<p>Let's print odd numbers under 50:</p>
	<p>
		@foreach($numbers as $number)
			@if($number % 2 !== 0)
				{{ $number }} 
			@endif
		@endforeach
	</p>
@endsection