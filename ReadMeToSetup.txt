setup step
	https://gemini.google.com/share/b580d758b462



frontend change

ku-home/src/app/contexts/AuthContext.tsx
	signUp 
		${localUrl}/make-server-fb9ae70e/auth/signup -> http://hotel.test/api/v1/auth/register

	signIn[

const signIn = async (email: string, password: string) => {
      const response = await fetch('http://hotel.test/api/v1/auth/login', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ email, password }),
      });
      const data = await response.json();
      if (response.ok) {
          setAccessToken(data.access_token);
          setUser(data.user);
          localStorage.setItem('token', data.access_token); 
      }
 };
 

	]	


ku-home/src/app/pages/UserProfile.tsx
	fetchBookings
		${localUrl}/make-server-fb9ae70e/bookings -> http://hotel.test/api/v1/user/get_bookings





back end
in 
	hotel\routes\api.tsx
		change -> //hotel.test -> local that u use
