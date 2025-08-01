<?php

namespace App\Http\Controllers;

use App\Http\Requests\AuthRegisterRequest;
use App\Http\Requests\AuthRegisterRestaurantRequest;
use App\Http\Requests\AuthUpdateProfileRequest;
use App\Http\Requests\AuthUpdateProfileRestaurantRequest;
use App\Models\Customer;
use App\Models\User;
use App\Models\Restaurant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use App\Models\RestaurantRegistration;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function register(AuthRegisterRequest $request)
    {
        // Validate the request data
        $validatedData = $request->validated();

        $twofactorCode = random_int(100000, 999999);
        $validatedData['two_factor_code'] = $twofactorCode;

        $currentUser = User::create($validatedData);

        Customer::create([
            'user_id' => $currentUser->id
        ]);

        Auth::login($currentUser);

        // Send the two-factor code to the user via email message
        Mail::raw("Your two-factor authentication code is: $twofactorCode", function ($message) use ($currentUser) {
            $message->to($currentUser->email)
                    ->subject('Sayfood | Two-Factor Authentication Code');
        });

        Log::channel('auth')->info('User registered.', [
            'user_id' => $currentUser->id,
            'username' => $currentUser->username,
            'email' => $currentUser->email,
            
        ]);

        return redirect()->route('twofactor.verif');
    }

    public function twoFactorVerification()
    {
        // Check if the user is authenticated
        if (!Auth::check()) {
            return redirect()->route('show.register');
        }

        $currentUser = Auth::user();

        // Ensure $currentUser is a fresh Eloquent model instance
        if ($currentUser) {
            $currentUser = User::find($currentUser->id);
        }

        // Check if the user has a two-factor code
        if (!$currentUser || !$currentUser->two_factor_code) {
            return redirect()->route('show.register');
        }

        return view('two-factor');
    }

    public function twoFactorSubmit(Request $request)
    {
        // Validate the request data
        $validatedData = $request->validate([
            'otp' => 'required|integer|digits:6',
        ]);

        # make sure otp is an integer
        $validatedData['otp'] = (int) $validatedData['otp'];

        $currentUser = Auth::user();

        // Ensure $currentUser is a fresh Eloquent model instance
        if ($currentUser) {
            $currentUser = User::find($currentUser->id);
        }

        // Check if the two-factor code matches
        if ($currentUser && $currentUser->two_factor_code === $validatedData['otp']) {
            // Remove the two-factor code from the database
            $currentUser->two_factor_code = null;
            $currentUser->two_factor_verified = true; // Set the verification timestamp
            $currentUser->save();

            // Log the user in (if not already)
            Auth::login($currentUser);

            Log::channel('auth')->info('User passed two-factor verification.', [
                'user_id' => $currentUser->id
            ]);

            return redirect()->route('home')->with('status', 'Two-factor authentication successful.');
        } else {
            Log::channel('auth')->info('User submitted wrong two-factor code.', [
                'user_id' => $currentUser->id,
                'actual_otp' => $currentUser->two_factor_code,
                'submitted_otp' => $validatedData['otp']
            ]);

            throw ValidationException::withMessages([
                'otp' => 'The provided two-factor code is incorrect.',
            ]);
        }
    }

    public function twoFactorResend()
    {
        // Check if the user is authenticated
        if (!Auth::check()) {
            Log::channel('auth')->warning('Unauthenticated user attempted to request a new two-factor code.',[
                'ip_address' => request()->ip()
            ]);
            
            return redirect()->route('show.register');
        }

        Log::channel('auth')->info('User requested a new two-factor code.', [
            'user_id' => Auth::id()
        ]);

        $currentUser = Auth::user();

        // Ensure $currentUser is a fresh Eloquent model instance
        if ($currentUser) {
            $currentUser = User::find($currentUser->id);
        }

        // Generate a new two-factor code
        $twofactorCode = random_int(100000, 999999);
        $currentUser->two_factor_code = $twofactorCode;
        $currentUser->save();

        // Send the new two-factor code to the user via email message
        Mail::raw("Your two-factor authentication code is: $twofactorCode", function ($message) use ($currentUser) {
            $message->to($currentUser->email)
                    ->subject('Sayfood | Two-Factor Authentication Code');
        });

        return redirect()->route('twofactor.verif')->with('status', 'A new two-factor authentication code has been sent to your email.');
    }

    public function registerRestaurant(AuthRegisterRestaurantRequest $request)
    {
        // Validate the request data
        $validatedData = $request->validated();

        RestaurantRegistration::create($validatedData);

        Log::channel('auth')->info('Restaurant registration created.', [
            'restaurant_name' => $validatedData['name'],
            'email' => $validatedData['email']
        ]);

        return redirect()->route('home')->with('status', 'Restaurant registration successful. We will contact your email soon.');
    }

    public function approveRegistration($id)
    {
        // Find the restaurant registration by ID
        $registration = RestaurantRegistration::findOrFail($id);

        // Create a new user for the restaurant with random username
        $randomStr = Str::uuid();
        $user = User::create([
            'username' => 'restaurant_' . $randomStr,
            'email' => $registration->email,
            'password' => bcrypt('restaurant_' . $randomStr),
            'role' => 'restaurant',
        ]);

        $user->two_factor_verified = 1;
        $user->save();

        // Create a new restaurant record
        $newRestaurant = Restaurant::create([
            'user_id' => $user->id,
            'name' => $registration->name,
            'address' => $registration->address,
        ]);

        // Update the registration
        $registration->status = 'operational';
        $registration->restaurant_id = $newRestaurant->id;
        $registration->save();

        // Notify the user via email message
        Mail::send([], [], function ($message) use ($user) {
            $message->to($user->email)
                ->subject('Sayfood | Your Restaurant Account Credentials')
                ->html("
                <h2>Welcome to Sayfood!</h2>

                <p>Your credentials are:</p>
                <ul>
                    <li>Username: <strong>{$user->username}</strong></li>
                    <li>Password: <strong>{$user->username}</strong></li>
                </ul>
                <p><a href='" . url('/login-restaurant') . "'>Click here to log in</a></p>
            ");
        });

        Log::channel('auth')->info('Restaurant registration approved.', [
            'restaurant_name' => $registration->name,
            'admin_id' => Auth::id()
        ]);

        return;
    }

    public function login(Request $request)
    {
        // Validate the request data
        $validatedData = $request->validate([
            'username' => 'required|string|max:64',
            'password' => 'required|string|min:8',
        ]);

        if (Auth::attempt($validatedData)) {
            if (Auth::user()->role === 'customer' || Auth::user()->role === 'admin') {
                Log::channel('auth')->info('User successfully logged in.', [
                    'user_id' => Auth::id(),
                    'username' => Auth::user()->username
                ]);

                $request->session()->regenerate();
                return redirect()->route('home');
            }

            Auth::logout();

            // show error
            Log::channel('auth')->error('User attempted to log in with a customer account but has a different role.', [
                'user_id' => Auth::id(),
            ]);

            throw ValidationException::withMessages([
                'credentials' => 'You do not have a customer account.',
            ]);
        }
        else {
            // If auth fails, show error
            Log::channel('auth')->error('Failed login attempt as customer.', [
                'username' => $validatedData['username']
            ]);

            throw ValidationException::withMessages([
                'credentials' => 'The provided credentials do not match our records.',
            ]);
        }
    }

    public function loginRestaurant(Request $request)
    {
        // Validate the request data
        $validatedData = $request->validate([
            'username' => 'required|string|max:64',
            'password' => 'required|string|min:8',
        ]);

        if (Auth::attempt($validatedData)) {
            if (Auth::user()->role === 'restaurant') {
                Log::channel('auth')->info('Restaurant successfully logged in.', [
                    'user_id' => Auth::id(),
                    'username' => Auth::user()->username,
                    'restaurant_id' => Auth::user()->restaurant->id,
                    'restaurant_name' => Auth::user()->restaurant->name
                ]);

                $request->session()->regenerate();
                return redirect()->route('restaurant-home');
            }

            Auth::logout();

            // show error
            Log::channel('auth')->error('User attempted to log in with a restaurant account but has a different role.', [
                'user_id' => Auth::id(),
            ]);

            throw ValidationException::withMessages([
                'credentials' => 'You do not have a restaurant account.',
            ]);
        }
        else {
            // If auth fails, show error
            Log::channel('auth')->error('Failed login attempt as restaurant.', [
                'username' => $validatedData['username']
            ]);

            throw ValidationException::withMessages([
                'credentials' => 'The provided credentials do not match our records.',
            ]);
        }
    }

    public function logout(Request $request)
    {
        Log::channel('auth')->info('User logged out.', [
            'user_id' => Auth::id(),
            'username' => Auth::user()->username
        ]);
        
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();


        return redirect()->route('home');
    }

    public function profile()
    {
        // get the user of the current session
        $currentUser = Auth::user();

        if (!$currentUser){
            return redirect()->route('selection-login');
        }

        if ($currentUser->role === 'customer' || $currentUser->role === 'admin'){
            return view('profile-customer', ['user' => $currentUser]);
        }
        else if ($currentUser->role === 'restaurant'){
            return view('profile-restaurant', ['user' => $currentUser]);
        }

        // Unexpected role - throw an error
        return redirect()->back()->withErrors(
            ['error' => 'Error: unexpected user role.']
        );
    }

    public function updateProfile(AuthUpdateProfileRequest $request)
    {
        $validatedData = $request->validated();

        $currentUser = Auth::user();

        if ($currentUser) {
            $currentUser = User::find($currentUser->id);

            $currentUser->username = $validatedData['username'];
            $currentUser->customer->dob = $validatedData['dob'];
            $currentUser->customer->address = $validatedData['address'];
            $currentUser->save();
            $currentUser->customer->save();
        }

        return redirect()->back()->with('status', 'Profile successfully updated!');
    }

    public function updateProfileRestaurant(AuthUpdateProfileRestaurantRequest $request)
    {
        $validatedData = $request->validated();

        $currentUser = Auth::user();

        if ($currentUser) {
            $currentUser = User::find($currentUser->id);

            $currentUser->username = $validatedData['username'];
            $currentUser->restaurant->name = $validatedData['restaurant_name'];
            $currentUser->restaurant->address = $validatedData['address'];
            $currentUser->save();
            $currentUser->restaurant->save();
        }

        return redirect()->back()->with('status', 'Profile successfully updated!');
    }

    public function updateProfileImage(Request $request)
    {
        $request->validate([
            'profile_image' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        $currentUser = Auth::user();

        if ($currentUser) {
            $currentUser = User::find($currentUser->id);

            $image = $request->file('profile_image');
            $imageName = 'profile_' . $currentUser->id . '_' . time() . '.' . $image->getClientOriginalExtension();
            
            if ($currentUser->role == 'restaurant') {
                $image->move(public_path('assets/resto_images'), $imageName);

                $restaurant = $currentUser->restaurant;
                $restaurant->image_url_resto = 'assets/resto_images/' . $imageName;
                $restaurant->save();
            }
            else {
                $imagePath = $image->storeAs('profile_images', $imageName, 'public');

                // Save the image path to the user (assuming a 'profile_image' column exists)
                $currentUser->profile_image = 'storage/profile_images/' . $imageName;
                $currentUser->save();
            }

            return redirect()->route('profile')->with(
                'status', 'Successfully updated profile image!'
            );
        }

        return redirect()->route('profile')->withErrors([
            ['error' => 'Error: failed to change profile image.']
        ]);
    }
    
    public function redirectToRestaurantLogin(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('show.login.restaurant');
    }

    public function redirectToCustomerLogin(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('show.login');
    }

    public function deleteAccount(Request $request)
    {
        $currentUser = Auth::user();
        $currentUser = User::find($currentUser->id);
        $currentUser->delete();

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
        
        return redirect()->route('home');
    }
}
