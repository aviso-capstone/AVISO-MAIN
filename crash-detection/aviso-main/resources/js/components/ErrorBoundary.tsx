import React, { Component, ErrorInfo, ReactNode } from 'react';

interface Props {
  children?: ReactNode;
}

interface State {
  hasError: boolean;
  error: Error | null;
  errorInfo: ErrorInfo | null;
}

export class ErrorBoundary extends Component<Props, State> {
  public state: State = {
    hasError: false,
    error: null,
    errorInfo: null
  };

  public static getDerivedStateFromError(error: Error): State {
    return { hasError: true, error, errorInfo: null };
  }

  public componentDidCatch(error: Error, errorInfo: ErrorInfo) {
    console.error("Uncaught rendering error:", error, errorInfo);
    this.setState({
      error: error,
      errorInfo: errorInfo
    });
  }

  public render() {
    if (this.state.hasError) {
      return (
        <div className="min-h-screen bg-background flex flex-col items-center justify-center p-8 text-foreground font-sans">
          <div className="max-w-3xl w-full bg-card p-8 rounded-lg shadow-xl border border-destructive/20">
            <h1 className="text-2xl font-bold text-destructive mb-4">Frontend Application Crash</h1>
            <p className="text-muted-foreground mb-6">
              A React component threw an unhandled error during rendering. Please check the stack trace below to identify the issue.
            </p>
            
            <div className="bg-muted p-4 rounded-md overflow-auto mb-4 font-mono text-xs max-h-[400px]">
              <strong className="text-foreground block mb-2 text-sm text-destructive">{this.state.error?.toString()}</strong>
              <pre className="text-muted-foreground whitespace-pre-wrap">{this.state.errorInfo?.componentStack}</pre>
            </div>
            
            <button 
              onClick={() => window.location.reload()} 
              className="px-4 py-2 bg-primary text-primary-foreground rounded-md hover:bg-primary/90 transition-colors cursor-pointer"
            >
              Reload Page
            </button>
          </div>
        </div>
      );
    }

    return this.props.children;
  }
}
