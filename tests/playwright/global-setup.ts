import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, writeFileSync } from 'node:fs';
import { CLIP, FIXTURES, NOT_A_VIDEO } from './helpers';

// A 7 second 640x360 H.264 / AAC test video
const FFMPEG_ARGS = [
	'-nostdin', '-v', 'error', '-y',
	'-f', 'lavfi', '-i', 'testsrc2=size=640x360:rate=25:duration=7',
	'-f', 'lavfi', '-i', 'sine=frequency=440:duration=7',
	'-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest',
];

function hasLocalFfmpeg() {
	try {
		execFileSync('ffmpeg', ['-version'], { stdio: 'ignore' });
		return true;
	} catch {
		return false;
	}
}

/** Make the test files once. Uses ffmpeg on this computer, or the one in the Docker test server. */
export default async function globalSetup() {
	mkdirSync(FIXTURES, { recursive: true });
	writeFileSync(NOT_A_VIDEO, 'this is not a video\n');
	if (existsSync(CLIP)) {
		return;
	}
	if (hasLocalFfmpeg()) {
		execFileSync('ffmpeg', [...FFMPEG_ARGS, CLIP], { stdio: 'inherit' });
		return;
	}
	execFileSync('docker', ['compose', 'exec', '-T', 'app', 'ffmpeg', ...FFMPEG_ARGS, '/tmp/playwright-clip.mp4'], { stdio: 'inherit' });
	execFileSync('docker', ['compose', 'cp', 'app:/tmp/playwright-clip.mp4', CLIP], { stdio: 'inherit' });
}
