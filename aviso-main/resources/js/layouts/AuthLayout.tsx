import { ReactNode } from 'react';

interface AuthLayoutProps {
    children: ReactNode;
}

export default function AuthLayout({ children }: AuthLayoutProps) {
    return (
        <div className="min-h-screen flex items-center justify-center relative overflow-hidden bg-background">
            {/* Ambient Animated Background Glows */}
            <div className="absolute top-[-10%] left-[-10%] w-[50%] h-[50%] rounded-full bg-primary/20 blur-[120px] motion-safe:animate-pulse" />
            <div className="absolute bottom-[-10%] right-[-10%] w-[50%] h-[50%] rounded-full bg-blue-500/20 blur-[120px] motion-safe:animate-pulse" style={{ animationDelay: '2s' }} />
            
            {/* Grid Overlay */}
            <div className="absolute inset-0 bg-[url('https://ui.shadcn.com/grid.svg')] bg-center [mask-image:linear-gradient(180deg,white,rgba(255,255,255,0))] opacity-30 dark:opacity-10" />
            
            {/* Auth content container */}
            <div className="w-full max-w-md relative z-10 px-4">
                {children}
            </div>
        </div>
    );
}
