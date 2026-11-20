@extends('layouts.app')
@section('title','Create account')
@section('body_class','auth-page')
@section('content')
<div class="auth-layout compact register-layout">
    <section class="auth-story" aria-labelledby="auth-story-title">
        <a class="guest-brand" href="{{ route('home') }}" aria-label="FurniCraft home"><img class="auth-brand-logo" src="{{ asset('images/optimized/lance-logo-circle-64.webp') }}" srcset="{{ asset('images/optimized/lance-logo-circle-64.webp') }} 1x, {{ asset('images/optimized/lance-logo-circle-128.webp') }} 2x" width="56" height="56" alt=""><span>FurniCraft<small>Lance Furniture Shop</small></span></a>
        <div class="auth-story-copy">
            <p class="section-kicker">Customer account</p>
            <h1 id="auth-story-title">Plan your space with confidence.</h1>
            <p>Save preferences, compare recommendations, preview placement, and send reservation requests to Lance Furniture Shop.</p>
        </div>
        <p class="small mb-0 auth-story-note">Your account is activated after you verify your email.</p>
    </section>
    <section class="auth-panel" aria-labelledby="register-heading">
        <div class="auth-panel-inner">
            <p class="section-kicker register-kicker">New customer</p>
            <h2 id="register-heading">Create account</h2>
            <p class="text-muted auth-panel-intro">Create your FurniCraft profile. We’ll email a 6-digit code to verify your address.</p>
            <form method="POST" action="{{ route('register.store') }}" class="vstack gap-3 auth-form register-form">
                @csrf
                <div class="row g-3 registration-name-fields">
                    <div class="col-md-4">
                        <label class="form-label" for="first_name">First Name</label>
                        <input class="form-control @error('first_name') is-invalid @enderror" id="first_name" name="first_name" value="{{ old('first_name') }}" maxlength="50" required autocomplete="given-name" aria-describedby="first-name-error">
                        @error('first_name')<div class="invalid-feedback" id="first-name-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="middle_name">Middle Name <span class="text-muted">(optional)</span></label>
                        <input class="form-control @error('middle_name') is-invalid @enderror" id="middle_name" name="middle_name" value="{{ old('middle_name') }}" maxlength="50" autocomplete="additional-name" aria-describedby="middle-name-error">
                        @error('middle_name')<div class="invalid-feedback" id="middle-name-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="last_name">Last Name</label>
                        <input class="form-control @error('last_name') is-invalid @enderror" id="last_name" name="last_name" value="{{ old('last_name') }}" maxlength="50" required autocomplete="family-name" aria-describedby="last-name-error">
                        @error('last_name')<div class="invalid-feedback" id="last-name-error">{{ $message }}</div>@enderror
                    </div>
                </div>
                <p class="form-text mb-0" id="optional-account-details">Birthdate and gender are optional account details. They are not used to verify your account or rank furniture.</p>
                <div class="row g-3">
                    <div class="col-sm-6">
                        <label class="form-label" for="birthdate">Birthdate <span class="text-muted">(optional)</span></label>
                        <input class="form-control @error('birthdate') is-invalid @enderror" id="birthdate" type="date" name="birthdate" value="{{ old('birthdate') }}" max="{{ now()->format('Y-m-d') }}" autocomplete="bday" aria-describedby="optional-account-details birthdate-error">
                        @error('birthdate')<div class="invalid-feedback" id="birthdate-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label" for="gender">Gender <span class="text-muted">(optional)</span></label>
                        <select class="form-select @error('gender') is-invalid @enderror" id="gender" name="gender" aria-describedby="optional-account-details gender-error">
                            <option value="" @selected(!old('gender'))>Leave blank</option>
                            <option value="female" @selected(old('gender') === 'female')>Female</option>
                            <option value="male" @selected(old('gender') === 'male')>Male</option>
                        </select>
                        @error('gender')<div class="invalid-feedback" id="gender-error">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="row g-3 register-contact-fields">
                    <div class="col-sm-6">
                        <label class="form-label" for="email">Email address</label>
                        <input class="form-control @error('email') is-invalid @enderror" id="email" type="email" name="email" value="{{ old('email') }}" maxlength="255" required autocomplete="email" inputmode="email" aria-describedby="register-email-error">
                        @error('email')<div class="invalid-feedback" id="register-email-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label" for="phone">Mobile number</label>
                        @php($phoneValue = old('phone'))
                        <input class="form-control @error('phone') is-invalid @enderror" id="phone" type="tel" name="phone" value="{{ is_string($phoneValue) ? $phoneValue : '' }}" placeholder="0917 123 4567" maxlength="32" autocomplete="tel" inputmode="tel" required aria-describedby="phone-hint phone-error">
                        <div class="form-text" id="phone-hint">For reservation contact. Use 0917 123 4567 or +63 917 123 4567; we save it in +63 format.</div>
                        @error('phone')<div class="invalid-feedback" id="phone-error">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-sm-6">
                        <label class="form-label" for="password">Password</label>
                        <input class="form-control @error('password') is-invalid @enderror" id="password" type="password" name="password" minlength="10" required autocomplete="new-password" aria-describedby="password-hint password-error">
                        <div class="form-text" id="password-hint">Use at least 10 characters with letters and numbers.</div>
                        @error('password')<div class="invalid-feedback" id="password-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label" for="password_confirmation">Confirm password</label>
                        <input class="form-control" id="password_confirmation" type="password" name="password_confirmation" minlength="10" required autocomplete="new-password">
                    </div>
                </div>
                <p class="form-text mb-0" id="registration-privacy-notice">We use your name and email to create and verify your account. Your mobile number is required so the shop can contact you about reservation requests. Read our <a href="{{ route('privacy-policy') }}" target="_blank" rel="noopener">Privacy notice <span class="visually-hidden">(opens in a new tab)</span></a> for how your information is used and how to request deletion.</p>
                <button class="btn btn-primary btn-lg w-100" type="submit" aria-describedby="registration-privacy-notice">Create account</button>
            </form>
            <p class="auth-switch">Already registered? <a href="{{ route('login') }}">Sign in</a></p>
        </div>
    </section>
</div>
@endsection
