<x-guest-layout>
 <!-- Session Status -->
 <x-auth-session-status class="mb-4" :status="session('status')" />

 <form method="POST" action="{{ route('login') }}">
  @csrf

  <!-- Email Address -->
  <div>
  <x-input-label for="email" :value="__('Email')" />
  <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
  <x-input-error :messages="$errors->get('email')" class="mt-2" />
  </div>

  <!-- Password -->
  <div class="mt-4">
  <x-input-label for="password" :value="__('Password')" />

  <x-text-input id="password" class="block mt-1 w-full"
     type="password"
     name="password"
     required autocomplete="current-password" />

  <x-input-error :messages="$errors->get('password')" class="mt-2" />
  </div>

  <!-- Remember Me -->
  <div class="block mt-4">
  <label for="remember_me" class="inline-flex items-center">
   <input id="remember_me" type="checkbox" class="rounded border-line text-signal-300 focus:ring-signal-400" name="remember">
   <span class="ms-2 text-sm text-mist-300">{{ __('Remember me') }}</span>
  </label>
  </div>

  <div class="flex items-center justify-end mt-4">
  @if (Route::has('password.request'))
   <a class="underline text-sm text-mist-300 hover:text-mist-100 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-signal-400" href="{{ route('password.request') }}">
    {{ __('Forgot your password?') }}
   </a>
  @endif

  <x-primary-button class="ms-3">
   {{ __('Log in') }}
  </x-primary-button>
  </div>
 </form>

 @if (Route::has('register'))
  <div class="mt-6 border-t border-line/70 pt-6">
  <p class="text-center text-sm text-mist-400">{{ __('Ainda não tem conta?') }}</p>
  <x-btn href="{{ route('register') }}" variant="outline" class="mt-3 w-full">
   {{ __('Register') }}
  </x-btn>
  </div>
 @endif
</x-guest-layout>
